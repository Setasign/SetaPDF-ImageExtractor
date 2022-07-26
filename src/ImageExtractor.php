<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor;

use SetaPDF_Core_ColorSpace_Indexed;
use SetaPDF_Core_Type_Dictionary_Helper as DictionaryHelper;
use setasign\SetaPDF\ImageExtractor\Image\AbstractImage;
use setasign\SetaPDF\ImageExtractor\Image\ImagickImage;
use setasign\SetaPDF\ImageExtractor\Image\GdImage;
use setasign\SetaPDF\ImageExtractor\Image\MaskArray;

/**
 * Class ImageExtractor
 */
class ImageExtractor
{
    public const IMAGE_RENDERER_GD = 0;
    public const IMAGE_RENDERER_IMAGICK = 1;

    /**
     * Get the used Image-xObjects by a page number.
     *
     * @param \SetaPDF_Core_Document $document
     * @param int $pageNo
     * @return array
     * @throws \SetaPDF_Core_Exception
     * @throws \SetaPDF_Core_Type_Exception
     * @throws \SetaPDF_Exception_NotImplemented
     */
    public static function getImagesByPageNo(
        \SetaPDF_Core_Document $document,
        int $pageNo
    ): array {
        $page = $document->getCatalog()->getPages()->getPage($pageNo);
        return self::getImagesByPage($page);
    }

    /**
     * Get the Image-xObjects used on a page.
     *
     * @param \SetaPDF_Core_Document_Page $page
     * @return array
     * @throws \SetaPDF_Core_Type_Exception
     * @throws \SetaPDF_Exception_NotImplemented
     */
    public static function getImagesByPage(
        \SetaPDF_Core_Document_Page $page
    ): array {
        $ressources = $page->getCanvas()->getResources(true);
        // make sure that there are xObjects
        if ($ressources === false) {
            return [];
        }

        // create a new ImageProcessor
        $imageProcessor = new ImageProcessor($page->getCanvas()->getStream(), ($page->getRotation() / 90) % 2 > 0, $ressources);

        // process all the data
        $data = $imageProcessor->process();

        // reduce the memory usage
        $imageProcessor->cleanUp();

        return $data;
    }

    public static function toImage(array $imageData, int $imageRenderer)
    {
        if ($imageData['type'] === 'xObject') {
            $stream = $imageData['xObject']->getIndirectObject();
        } elseif ($imageData['type'] === 'inlineImage') {
            $stream = $imageData['stream'];
        } else {
            throw new \InvalidArgumentException('Unknown data type.');
        }

        $stream = \SetaPDF_Core_Type_Stream::ensureType($stream);

        // start to process the image
        $image = self::_processStream($stream, $imageRenderer);

        // clean the image and if SMask/Mask are available, clean them too
        $image->cleanUp();

        // return the real instance of the image
        return $image->getResult();
    }

    public function xObjectToImage(\SetaPDF_Core_XObject_Image $xObject, int $imageRenderer)
    {
        return self::toImage([
            'type' => 'xObject',
            'xObject' => $xObject
        ], $imageRenderer);
    }

    /**
     * Processes all incoming images (including Masks and SMasks)
     *
     * @param \SetaPDF_Core_Type_Stream $stream
     * @param int $imageRenderer
     * @return AbstractImage
     * @throws \Exception
     * @throws \SetaPDF_Exception_NotImplemented
     */
    protected static function _processStream(\SetaPDF_Core_Type_Stream $stream, int $imageRenderer)
    {
        $_mask = null;

        // get the dictionary
        $dict = $stream->getValue();

        // get the width and height
        $width = (int) \SetaPDF_Core_Type_Dictionary_Helper::getValue($dict, 'Width', 0, true);
        $height = (int) \SetaPDF_Core_Type_Dictionary_Helper::getValue($dict, 'Height', 0, true);

        // decode the stream.
        $decodedStream = static::_unfilterImage($dict, $stream, $remainingSupportedFilter);

        // get the colorspace
        $colorSpace = \SetaPDF_Core_ColorSpace::createByDefinition(
            \SetaPDF_Core_Type_Dictionary_Helper::getValue($dict, 'ColorSpace', new \SetaPDF_Core_Type_Name('DeviceGray'))
        );

        // get the number of components (the amount of different "color" channels)
        $numOfComponents = $colorSpace->getColorComponents();
        // get the bits per component
        $bitsPerComponent = \SetaPDF_Core_Type_Dictionary_Helper::getValue($dict, 'BitsPerComponent', 1, true);

        if ($colorSpace instanceof SetaPDF_Core_ColorSpace_Indexed) {
            $defaultDecodeArray = $colorSpace->getDefaultDecodeArray($bitsPerComponent);
        } else {
            $defaultDecodeArray = $colorSpace->getDefaultDecodeArray();
        }

        // check for an SMask (SMask has a higher priority than a normal mask)
        if ($dict->offsetExists('SMask')) {
            $smask = \SetaPDF_Core_Type_Stream::ensureType(DictionaryHelper::getValue($dict, 'SMask'));
            $_mask = static::_processStream($smask, $imageRenderer);
        }

        // check for a Mask and make sure that no mask was found before.
        if ($_mask === null && $dict->offsetExists('Mask')) {
            $mask = DictionaryHelper::getValue($dict, 'Mask');

            //check if its a valid mask
            if ($mask instanceof \SetaPDF_Core_Type_Array) {
                // it's an array of colors, so we will create a MaskArrayInstance
                $_mask = new MaskArray($mask->toPhp());
            } elseif ($mask instanceof \SetaPDF_Core_Type_IndirectObjectInterface) {
                // it's an image, so we need to process it
                $_mask = static::_processStream(\SetaPDF_Core_Type_Stream::ensureType($mask), $imageRenderer);
            } else {
                // we don't know what type of mask it is
                throw new \Exception('The mask could not be extracted.');
            }
        }

        $decodeArray = null;
        // check for a decode array
        if ($dict->offsetExists('Decode')) {
            // convert it to a php array
            $_decodeArray = \SetaPDF_Core_Type_Array::ensureType($dict->getValue('Decode'))->toPhp(true);

            // make sure that the decode array so that no useless calculations are made
            if ($_decodeArray != $defaultDecodeArray) {
                // iterate through the different sets of decode entrys
                for ($i = 0; $i < count($_decodeArray); $i += 2) {
                    // calculate the key
                    $key = ($i / 2);

                    // store the original values in a new format
                    $decodeArray[$key]['min'] = $_decodeArray[$i];
                    $decodeArray[$key]['max'] = $_decodeArray[$i + 1];

                    // already calculate the part of the decodeArray where the color values are not needed
                    $decodeArray[$key]['calculated'] = (
                        ($decodeArray[$key]['max'] - $decodeArray[$key]['min']) / (2 ** $bitsPerComponent) - 1
                    );
                }
            }
        }

        // create a new AbstractImage instance
        if (self::IMAGE_RENDERER_GD === $imageRenderer) {
            $image = new GdImage($width, $height, $colorSpace, $decodeArray, $_mask);
        } elseif (self::IMAGE_RENDERER_IMAGICK === $imageRenderer) {
            $image = new ImagickImage($width, $height, $colorSpace, $decodeArray, $_mask);
        } else {
            throw new \InvalidArgumentException('Image renderer ' . $imageRenderer . ' is not supported.');
        }

        // check if we have raw image data
        if ($remainingSupportedFilter !== '') {
            // we don't have raw image data, so we need to check if we can read the image while it maintains the filter
            if (!$image->canRead($remainingSupportedFilter)) {
                // the format is not supported
                throw new \Exception(\sprintf(
                    '%s does not support filter %s with colorspace %s',
                    get_class($image),
                    $remainingSupportedFilter,
                    $colorSpace->getFamily()
                ));
            }

            // read the image
            $image->readBlob($decodedStream);
        } else {
            // make sure that the color space is indexed
            if ($colorSpace instanceof \SetaPDF_Core_ColorSpace_Indexed) {
                // iterate through the colors
                foreach ($colorSpace->getLookupTable() as $index => $color) {
                    // add the color to the class
                    $image->addIndexedColor($index, $color);
                }
            }

            // create a string reader for the raw stream
            $stream = new \SetaPDF_Core_Reader_String($decodedStream);

            // calculate the content size of the image
            $max = ($width * $height) * $numOfComponents;

            if ($bitsPerComponent === 8) {
                //read as long as the image is incomplete
                for ($i = 0; $i < $max; $i += $numOfComponents) {
                    // read the needed bytes
                    $bytes = $stream->readBytes($numOfComponents);

                    // make sure that we are not on the end of the stream
                    if ($bytes === false) {
                        throw new \Exception('Not enough bytes in image.');
                    }

                    // write a pixel with the bytes
                    $image->writePixel($bytes);
                }

            } elseif ($bitsPerComponent === 1 || $bitsPerComponent === 2 || $bitsPerComponent === 4) {
                // keep track of the images x-axis, because if an image ends in the middle of a byte,
                // we will need to ignore the left pieces of the byte
                $x = 0;

                // calculate a seperator so we can cut the byte
                $byteSeperator = (2 ** $bitsPerComponent) - 1;

                // read as long as the image is incomplete.
                for ($i = 0; $i < $max;) {
                    // read a byte
                    $byte = $stream->readByte();

                    // make sure that we are not on the end of the stream
                    if ($byte === false) {
                        throw new \Exception('Not enough bytes in image.');
                    }

                    //convert the whole byte to an ascii value
                    $byte = ord($byte);

                    // iterate through the byte
                    for ($bitOffset = 8 - $bitsPerComponent; $bitOffset >= 0; $bitOffset -= $bitsPerComponent) {
                        // get the current piece by using the byte seperator
                        $colorByte = ($byte >> $bitOffset) & $byteSeperator;

                        // when we don't have an indexed image we will need to change the color to a value from 1 to 256
                        if (!$colorSpace instanceof \SetaPDF_Core_ColorSpace_Indexed) {
                            $colorByte = (255 / $bitsPerComponent) * $colorByte;
                        }

                        // write the pixel with the color as a text representation
                        $image->writePixel(\chr($colorByte));

                        // add one to the x-axis
                        $x++;

                        //add one to the written pixels
                        $i++;

                        // when we have a full line ignore the left pieces
                        if ($x === $width) {
                            // reset the counter
                            $x = 0;
                            break;
                        }
                    }
                }
            } else {
                throw new \SetaPDF_Exception_NotImplemented(
                    'Image is not supported yet (BitsPerComponent <> ' . $bitsPerComponent . '.)'
                );
            }

            // clean up the stream to save memory
            $stream->cleanUp();

            // delete the stream to save memory
            unset($stream);
        }

        //  delete the decoded stream, the colorspace and the dirctionary to save memory
        unset($decodedStream, $colorSpace, $dict);

        // finish writing the image
        $image->finalize();

        // return the AbstractWriter
        return $image;
    }

    /**
     * Filters a stream
     *
     * @param \SetaPDF_Core_Type_Dictionary $dict
     * @param \SetaPDF_Core_Type_Stream $stream
     * @param null|string &$remainingSupportedFilter
     * @return string
     * @throws \Exception
     */
    protected static function _unfilterImage(
        \SetaPDF_Core_Type_Dictionary $dict,
        \SetaPDF_Core_Type_Stream $stream,
        ?string &$remainingSupportedFilter
    ): string {
        // unset the left supported filter
        $remainingSupportedFilter = '';
        // get the stream
        $rawStream = $stream->getStream(true);

        // get the list of filters and convert them to a php array
        $filters = DictionaryHelper::getValue($dict, 'Filter');

        // when there are no Filters, directly work with the image data
        if ($filters === null) {
            return $rawStream;
        }
        if (!$filters instanceof \SetaPDF_Core_Type_Array) {
            $filterArray = [$filters];
        } else {
            $filterArray = $filters->getValue();
        }

        // prepare the decode array
        $decodeArray = [];

        // convert the decode array to php
        $decodeParams = DictionaryHelper::getValue($dict, 'DecodeParms');
        if ($decodeParams !== null) {
            if (!$decodeParams instanceof \SetaPDF_Core_Type_Array) {
                $decodeArray = [$decodeParams];
            } else {
                $decodeArray = $decodeParams->getValue();
            }
        }

        // start unfiltering the stream
        $unfilteredStream = static::_unfilter($filterArray, $decodeArray, $rawStream, $remainingSupportedFilter, $dict);

        // unset all local variables
        unset($rawStream, $filterArray, $decodeArray);

        //return the filtered stream
        return $unfilteredStream;
    }

    /**
     * Processes all the filters that are in the filter array and are supported
     *
     * @param array $filterArray
     * @param array $decodeArray
     * @param string $stream
     * @param null|string &$remainingSupportedFilter
     * @param \SetaPDF_Core_Type_Dictionary $dict
     * @return string
     * @throws \Exception
     */
    protected static function _unfilter(
        array $filterArray,
        array $decodeArray,
        string $stream,
        ?string &$remainingSupportedFilter,
        \SetaPDF_Core_Type_Dictionary $dict
    ): string {
        $lastKey = \array_key_last($filterArray);

        // iterate through the filters
        foreach ($filterArray as $key => $filter) {
            // set the decode params
            $decodeParam = $decodeArray[$key] ?? null;

            $filterName = $filter->getValue();

            /** @see \SetaPDF_Core_Type_Stream::_applyFilter() */
            switch ($filterName) {
                case 'FlateDecode':
                case 'Fl':
                case 'LZWDecode':
                case 'LZW':
                    if ($filterName === 'LZWDecode' || $filterName === 'LZW') {
                        $filterClass = 'SetaPDF_Core_Filter_Lzw';
                    } else {
                        $filterClass = 'SetaPDF_Core_Filter_Flate';
                    }

                    if ($decodeParam instanceof \SetaPDF_Core_Type_Dictionary) {
                        $predictor = DictionaryHelper::getValue($decodeParam, 'Predictor', null, true);
                        $colors = DictionaryHelper::getValue($decodeParam, 'Colors', null, true);
                        $bitsPerComponent = DictionaryHelper::getValue($decodeParam, 'BitsPerComponent', null, true);
                        $columns = DictionaryHelper::getValue($decodeParam, 'Columns', null, true);

                        $filterObject = new $filterClass($predictor, $colors, $bitsPerComponent, $columns);
                    } else {
                        $filterObject = new $filterClass();
                    }
                    break;

                case 'ASCII85Decode':
                case 'A85':
                    $filterObject = new \SetaPDF_Core_Filter_Ascii85();
                    break;

                case 'ASCIIHexDecode':
                case 'AHx':
                    $filterObject = new \SetaPDF_Core_Filter_AsciiHex();
                    break;

                case 'RunLengthDecode':
                case 'RL':
                    $filterObject = new \SetaPDF_Core_Filter_RunLength();
                    break;

                case 'DCTDecode':
                case 'DCT':
                case 'JPXDecode':
                    $filterObject = null;
                    break;

                case 'CCITTFaxDecode':
                case 'CCF':
                    // No real decode is applied here, we are just adding a tiff header so that the image extractor
                    // can read it.
                    $filterObject = null;
                    $stream = self::_CCITTFaxDecode($decodeParam, $stream, $dict);
                    break;

                default:
                    throw new \Exception('Filter not supported:' . $filterName);
            }

            if ($filterObject === null) {
                if ($key !== $lastKey) {
                    throw new \Exception('Filter not supported: ' . $filterName);
                }

                $remainingSupportedFilter = $filterName;
            } else {
                // filter the stream
                $stream = $filterObject->decode($stream);
                // unset the instance
                unset($filterObject);
            }
        }

        // return a AbstractImage
        return $stream;
    }

    /**
     * @param mixed $decodeParam
     * @param string $stream
     * @param \SetaPDF_Core_Type_Dictionary $dict
     * @return string
     */
    protected static function _CCITTFaxDecode(
        $decodeParam,
        string $stream,
        \SetaPDF_Core_Type_Dictionary $dict
    ): string {
        $k = 0;
        $encodedByteAlign = false;
        $columns = 1728;
        $rows = 0;

        /*
         * $endOfLine = false;
         *
         * This is already applied
         * Tiff6.pdf Page: 43/44
         */

        /*
         * $endOfBlock = true;
         *
         * This is already applied
         * Tiff6.pdf Page: 52
         */
        $blackIs1 = false;
        $damagedRowsBeforeError = 0;

        if ($decodeParam instanceof \SetaPDF_Core_Type_Dictionary) {
            $k = DictionaryHelper::getValue($decodeParam, 'K', $k, true);
            $encodedByteAlign = DictionaryHelper::getValue($decodeParam, 'EncodedByteAlign', $encodedByteAlign, true);
            $columns = DictionaryHelper::getValue($decodeParam, 'Columns', $columns, true);
            $rows = DictionaryHelper::getValue($decodeParam, 'Rows', $rows, true);
            $blackIs1 = DictionaryHelper::getValue($decodeParam, 'BlackIs1', $blackIs1, true);
            $damagedRowsBeforeError = DictionaryHelper::getValue($decodeParam, 'DamagedRowsBeforeError', $damagedRowsBeforeError, true);
        }

        if ($rows === 0) {
            $rows = DictionaryHelper::getValue($dict, 'Height', $rows, true);
        }

        // from here on is the tiff header.
        $numOfTags = 10;

        $head = \pack(
            'CCvVv',
            0x49, // Byte order indication: Little indian
            0x49, // Byte order indication: Little indian
            42,   // Version number, has to be 42
            8,    // Offset
            $numOfTags    // Number of tags
        );

        $head .= \pack('vvVV',
            256, 4, 1, $columns // 1. Tag #imagewidth, long, 1, width value
        );

        $head .= \pack('vvVV',
            257, 4, 1, $rows    // 2. Tag #imagelength long, 1, height value
        );

        $head .= \pack('vvVV',
            258, 3, 1, 1        // 3. Tag #BitsPerSample Short, 1, 1
        );

        $head .= \pack('vvVV',
            259, 3, 1, ($k >= 0) ? 3 : 4   // 4. Tag #Compression
        );

        $head .= \pack('vvVV',
            262, 4, 1, $blackIs1 ? 1 : 0    // 5. Tag #PhotometricInterpretation
        );

        $head .= \pack('vvVV',
            273, 4, 1, 12 + (12 * $numOfTags)    //12 + (12 * number of tags) , // 6. Tag #StripOffsets
        );

        $head .= \pack('vvVV',
            278, 4, 1, $rows         // 7. Tag #RowsPerStrip
        );

        $head .= \pack('vvVV',
            279, 4, 1, strlen($stream)     // 8. Tag #StripByteCounts
        );

        if ($k >= 0) {
            // 9. Tag #T4 Options
            $head .= \pack('vvVV',
                293, 4, 1, (($k > 0 ? 0x01 : 0x00) | ($encodedByteAlign ? 0x04 : 0x00))
            );
        } else {
            // 9. Tag #T6 Options
            $head .= \pack('vvVV',
                292, 4, 1, 0x00
            );
        }

        $head .= \pack('vvVV',
            326, 3, 1, $damagedRowsBeforeError // 10. Tag #BadFaxLines
        );

        $head .= \pack('v',
            0 // IFD
        );

        return $head . $stream;
    }
}
