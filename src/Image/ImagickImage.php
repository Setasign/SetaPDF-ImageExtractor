<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor\Image;

/**
 * Class ImagickImage
 *
 * This class is used to convert a image in a pdf to a regular image.
 * It uses Imagick to create a new image.
 */
class ImagickImage extends AbstractImage
{
    public static int $bufferSize = 8192;

    /**
     * The imagick instance
     *
     * @var \Imagick
     */
    protected \Imagick $_image;

    /**
     * Array of pixels that will be imported
     *
     * @var array
     */
    protected array $_pixels = [];

    /**
     * How many lines have been imported
     *
     * @var int
     */
    protected int $_writtenLines = 0;

    /**
     * Map of pixel ordering as a string for Imagick.
     *
     * @var string
     */
    protected string $_imMap = 'RGB';

    /**
     * ImImage constructor.
     * @param int $width
     * @param int $height
     * @param \SetaPDF_Core_ColorSpace $colorSpace
     * @param null|array $decodeArray
     * @param MaskInterface|null $mask
     * @throws \Exception
     */
    public function __construct(
        int $width,
        int $height,
        \SetaPDF_Core_ColorSpace $colorSpace,
        ?array $decodeArray,
        MaskInterface $mask = null
    ) {
        // checking for existance of Imagick
        if (!\extension_loaded('imagick')) {
            throw new \Exception('Imagick is not installed.');
        }

        parent::__construct($width, $height, $colorSpace, $decodeArray, $mask);

        // create a new instance of Imagick
        $this->_image = new \Imagick();

        // determine the Imagick color space
        if ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceCmyk) {
            $this->_imMap = 'CMYK';
        } elseif ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceRgb) {
            $this->_imMap = 'RGB';
        } elseif ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceGray) {
            $this->_imMap = 'I';
        }

        // set up imagick with the color space
        if ($this->_imMap === 'CMYK') {
            $this->_image->newImage($width, $height, new \ImagickPixel('cmyk(0,0,0,0)'));
            $this->_image->setColorspace(\Imagick::COLORSPACE_CMYK);
            $this->_image->setImageColorspace(\Imagick::COLORSPACE_CMYK);
        } else {
            $this->_image->newImage($width, $height, 'white');
        }

        // always add an alpha value to the image
        $this->_imMap .= 'A';
    }

    /**
     * Returns if an image blob can be read by Imagick
     *
     * @param string $imageType
     * @return bool
     */
    public function canRead(string $imageType): bool
    {
        if (!\in_array($imageType, ['JPXDecode', 'DCTDecode', 'CCITTFaxDecode'], true)) {
            return false;
        }

        if (
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceRgb &&
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceGray &&
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_IccBased &&
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceCmyk
        ) {
            return false;
        }

        return true;
    }

    /**
     * Reads a blob and fixes some strange behaviors when reading blobs with Imagick
     *
     * @param string $imageBlob
     * @return void
     * @throws \ImagickException
     */
    protected function _readBlob(string $imageBlob): void
    {
        // read the blob
        $this->_image->readImageBlob($imageBlob);

        // cmyk will get inverted
        if ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceCmyk) {
            $this->setNegated(true);
        }

        $this->_applyDecodeArrayNegate();

//      Maybe we can apply the decode array manually:
//
//        $m = ???;
//        $this->_image->colorMatrixImage($m);

//        or:

//      Problem here is, that IM always uses RGBA but the Decode array depends on the color space components count.
//        for ($x = 0; $x < $this->_width; $x++) {
//            for ($y = 0; $y < $this->_height; $y++) {
//                $pixelColor = $this->_image->getImagePixelColor($x, $y);
//                $colors = $pixelColor->getColor(2);
//                $translate = [
//                    'r' => [0, \Imagick::COLOR_RED],
//                    'g' => [1, \Imagick::COLOR_GREEN],
//                    'b' => [2, \Imagick::COLOR_BLUE],
//                    'a' => [3, \Imagick::COLOR_ALPHA],
//                ];
//                foreach ($colors as $k => $color) {
//                    $pixelColor->setColorValue($translate[$k][1], $this->_applyDecodeArray($translate[$k][0], $color));
//                }
//            }
//        }

        // set the written lines to the height so that there is no exception while finalizing
        $this->_writtenLines = $this->_height;
    }

    /**
     * Adds a pixel to the image,
     * note then you need to call self::_getColor() to get the corresponding color from $color
     *
     * @param string $color
     * @return void
     */
    public function writePixel(string $color): void
    {
        // move the color entrys to the pixels
        foreach ($this->_getColor($color) as $c) {
            $this->_pixels[] = $c;
        }

        $this->_x++;
        //when we have a full line
        if ($this->_x === $this->_width) {
            // reset x
            $this->_x = 0;
            // increase y
            $this->_y++;

            // check for memory usage
            if (count($this->_pixels) >= self::$bufferSize) {
                // import all currently available pixels
                $this->_image->importImagePixels(
                    0,
                    $this->_writtenLines,
                    $this->_width,
                    $this->_y - $this->_writtenLines,
                    $this->_imMap,
                    \Imagick::PIXEL_CHAR,
                    $this->_pixels
                );
                // reset the pixels
                $this->_pixels = [];
                // set the written lines to the current line
                $this->_writtenLines = $this->_y;
            }
        }
    }

    /**
     * Creates a new color array
     *
     * @param string $color
     * @param null|int $alphaValue
     * @return array
     * @throws \SetaPDF_Exception_NotImplemented
     */
    public function _createColor(string $color, ?int $alphaValue): array
    {
        if ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceGray) {
            $result = [ord($color)];

        } elseif ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceRgb) {
            $result = [
                ord($color[0]),
                ord($color[1]),
                ord($color[2])
            ];

        } elseif ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceCmyk) {
            $result = [
                ord($color[0]),
                ord($color[1]),
                ord($color[2]),
                ord($color[3])
            ];
        } else {
            throw new \SetaPDF_Exception_NotImplemented(
                'Unsupported color space in ImageMagick image: ' . $this->_baseColorSpace->getFamily()
            );
        }

        // add an alpha value
        if ($alphaValue === null) {
            $result[] = 255;
        } else {
            $result[] = $alphaValue;
        }

        return $result;
    }

    /**
     * Applies a mask to the image
     * @return void
     */
    protected function _applyMask(): void
    {
        // make sure that we have a mask
        if ($this->_mask === null) {
            return;
        }

        // prepare the mask
        if (!$this->_mask instanceof ImagickImage) {
            // we don't have an ImImage, so we can't get the Imagick instance directly
            $maskImage = new \Imagick();
            if ($this->_mask->canOutputBlob()) {
                // when we can output a blob, read the blob
                $maskImage->readImageBlob($this->_mask->getBlob());
            } else {
                // otherwise import the image pixel by pixel to Imagick
                for ($y = 0; $y <= $this->getHeight(); $y++) {
                    for ($x = 0; $x <= $this->getWidth(); $x++) {
                        $maskImage->importImagePixels(
                            $x,
                            $y,
                            1,
                            1,
                            'I',
                            \Imagick::PIXEL_CHAR,
                            [$this->_mask->getCorrespondingAlphaValue($x, $y, $this)]
                        );
                    }
                }
            }
        } else {
            // get the imagick instance
            $maskImage = $this->_mask->getResult();
        }

        // Copy opacity mask
        if (isset($maskImage) && $maskImage instanceof \Imagick) {
            $maskImage->setImageMatte(false);
            $this->_image->compositeImage($maskImage, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        }
    }

    /**
     * Writes the left pixels and add an embedded color space if there is one
     *
     * @throws \SetaPDF_Exception_NotImplemented
     * @return void
     */
    protected function _prepareResult(): void
    {
        // check if we have something to write
        if ($this->_writtenLines < $this->_height) {
            // import all the pixels that are left
            $this->_image->importImagePixels(
                0,
                $this->_writtenLines,
                $this->_width,
                $this->_height - $this->_writtenLines,
                $this->_imMap,
                \Imagick::PIXEL_CHAR,
                $this->_pixels
            );

            // unset the pixels to save memory
            unset($this->_pixels);
        }

        // try to add the embedded color space profile to the image
        if ($this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_IccBased) {
            // get the icc stream
            $stream = $this->_colorSpace->getIccProfileStream()->getStreamObject()->getStream();
            // try to add the stream
            if ($this->_image->profileImage('icc', $stream) !== true) {
                // when something went wrong, we will end up here
                throw new \SetaPDF_Exception_NotImplemented(
                    'The ICC Stream could not be applied to ImageMagick image'
                );
            }
        }
    }

    /**
     * Returns the corresponding color from a specific pixel
     *
     * @param int $x
     * @param int $y
     * @return array
     */
    public function getColor(int $x, int $y): array
    {
        return array_values($this->_image->getImagePixelColor($x, $y)->getColor(false));
    }

    /**
     * Negates an image
     *
     * @return void
     */
    protected function _negate(): void
    {
        $this->_image->negateImage(false, \Imagick::CHANNEL_ALL - \Imagick::CHANNEL_ALPHA);
    }

    /**
     * Returns the resulting image instance
     *
     * @return \Imagick
     */
    public function getResult()
    {
        return $this->_image;
    }

    /**
     * Returns a blob representation of the image
     *
     * @return string
     */
    public function getBlob(): string
    {
        return $this->_image->getImageBlob();
    }

    /**
     * Gets called on the mask instance and destroys left parts
     *
     * @return void
     */
    protected function _cleanUp(): void
    {
        $this->_image->destroy();
    }

    /**
     * Returns if the Image should read the MaskInterface pixel by pixel or if it should get the stream afterwards
     *
     * @return bool
     */
    public function isReadingPixelByPixel(): bool
    {
        return false;
    }
}