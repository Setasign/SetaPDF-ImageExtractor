<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor\Image;

use SetaPDF_Core_ColorSpace;

/**
 * Class AbstractImage
 *
 * This class represents an image
 */
abstract class AbstractImage implements MaskInterface
{
    /**
     * The images width
     *
     * @var int
     */
    protected int $_width;
    /**
     * The images height
     *
     * @var int
     */
    protected int $_height;

    /**
     * The current writing pos
     *
     * @var int
     */
    protected int $_x = 0;

    /**
     * The current writing pos
     *
     * @var int
     */
    protected int $_y = 0;

    /**
     * Flag to check if the iamge alreay has been finalized
     *
     * @var bool
     */
    private bool $_finalized = false;

    /**
     * An array containing all the indexed colors
     *
     * @var array
     */
    protected array $_indexedColors = [];

    /**
     * An array containing all colors that have been created
     *
     * @var array
     */
    protected array $_colors = [];

    /**
     * interface for setting the mask
     *
     * @var null|MaskInterface
     */
    protected ?MaskInterface $_mask;

    /**
     * An Array containing the data to decode the image
     *
     * @var null|array
     */
    protected ?array $_decodeArray;

    /**
     * An array that stores all the colors that have been decoded
     *
     * @var array
     */
    protected array $_decodedColors = [];

    /**
     * The images color space
     *
     * @var \SetaPDF_Core_ColorSpace
     */
    protected \SetaPDF_Core_ColorSpace $_colorSpace;

    /**
     *  The images color component color space
     *
     * @var \SetaPDF_Core_ColorSpace|\SetaPDF_Core_ColorSpace_DeviceCmyk|\SetaPDF_Core_ColorSpace_DeviceGray|\SetaPDF_Core_ColorSpace_DeviceRgb|\SetaPDF_Core_ColorSpace_IccBased|\SetaPDF_Core_ColorSpace_Separation|string
     */
    protected $_baseColorSpace;

    /**
     * Flag to see if we read the alpha value directly of the $_mask or if we combine them afterwards
     *
     * @var bool|null
     */
    private ?bool $_isReadingPixelByPixel = null;

    /**
     * Flag to see if the images was/will be negated
     *
     * @var bool
     */
    protected bool $_negated = false;

    /**
     * Value that contains the last/current pixel
     *
     * @var null|string
     */
    protected ?string $_currentColor = null;

    /**
     * AbstractImage constructor.
     * @param int $width
     * @param int $height
     * @param \SetaPDF_Core_ColorSpace $colorSpace
     * @param null|array $decodeArray
     * @param MaskInterface|null $mask
     */
    public function __construct(
        int $width,
        int $height,
        \SetaPDF_Core_ColorSpace $colorSpace,
        ?array $decodeArray,
        MaskInterface $mask = null
    ) {
        $this->_width = $width;
        $this->_height = $height;

        $this->_colorSpace = $colorSpace;
        $this->_baseColorSpace = $this->_resolveBaseColorSpace($colorSpace);

        $this->_mask = $mask;

        $this->_decodeArray = $decodeArray;

        if ($this->_mask !== null) {
            $this->_isReadingPixelByPixel = $this->_mask->isReadingPixelByPixel();
        }

    }

    /**
     * Cleans up the class
     *
     * @param bool $isMask
     * @return void
     */
    public function cleanUp(bool $isMask = false)
    {
        // process the left data
        $this->finalize();

        // cleanup the mask
        if ($this->_mask !== null) {
            $this->_mask->cleanUp(true);
            unset($this->_mask);
        }

        // cleanup the contained image if we are a mask
        if ($isMask) {
            $this->_cleanUp();
            unset($this->_image);
        }

        // cleanup the left data
        unset($this->_decodeArray, $this->_baseColorSpace, $this->_colorSpace, $this->_indexedColors, $this->_colors);
    }

    /**
     * Function used to store and create the colors efficiently
     *
     * @param string $color
     * @return mixed
     * @throws \Exception
     */
    protected function _getColor(string $color)
    {
        // store the current color
        $this->_currentColor = $color;

        // we need to get the real color
        if ($this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_Indexed) {
            // when we need to apply a decode array and an indexed color space we will need to apply the decode array before getting the corrosponding color
            if ($this->_decodeArray !== null) {
                // check if the decode already was cached
                if (!isset($this->_decodedColors[$color])) {
                    // decode the color
                    $this->_decodedColors[$color] = chr($this->_applyDecodeArray(0, ord($color)));
                }
                // override the color with the decoded color out of the cache
                $color = $this->_decodedColors[$color];
            }

            // get the "real" color
            if (isset($this->_indexedColors[$color])) {
                $color = $this->_indexedColors[$color];
            } else {
                throw new \Exception('The image has a indexed color space, but not all the color are defined in it.');
            }
        }

        // create a key to store the new colors
        $colorKey = $color;

        // store the alpha value
        $alphaValue = null;

        // do this only if the mask has a preference for it
        if ($this->_isReadingPixelByPixel === true) {
            // store the corresponding alpha value
            $alphaValue = $this->_mask->getCorrespondingAlphaValue($this->_x, $this->_y, $this);
            // and add it the key so we can have the same color with different alpha values
            $colorKey .= chr($alphaValue);
        }

        // check if we have the color in the cache
        if (!isset($this->_colors[$colorKey])) {
            // when we have a decode array and its not indexed we will apply it to
            // the value that will be cached so that we are saving a small amount of memory.
            if ($this->_decodeArray !== null) {
                if (!($this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_Indexed)) {
                    // iterate through all the color space entries
                    for ($i = 0; $i < $this->_baseColorSpace->getColorComponents(); $i++) {
                        // apply the corresponding decode array on the color component
                        $d = $this->_applyDecodeArray($i, ord($color[$i]));
                        // store it as the color component
                        $color[$i] = chr($d * 255);
                    }
                }
            }

            // create a color that can be used by the chosen extension
            $this->_colors[$colorKey] = $this->_createColor($color, $alphaValue);
        }

        // return a color that can be used by the chosen extension
        return $this->_colors[$colorKey];
    }

    /**
     * Add a new indexed color to the image
     *
     * @param int $index
     * @param string $color
     * @return void
     */
    public function addIndexedColor(int $index, string $color)
    {
        $this->_indexedColors[chr($index)] = $color;
    }

    /**
     * Reads a blob
     *
     * @param string $imageBlob
     * @return void
     */
    public function readBlob(string $imageBlob)
    {
        // read the blob
        $this->_readBlob($imageBlob);

        // make sure that the mask will be applied
        if (null !== $this->_isReadingPixelByPixel) {
            $this->_isReadingPixelByPixel = false;
        }
    }

    /**
     * This function finalizes the image
     *  - negating
     *  - writing the left data
     *  - applying mask
     *
     * @return void
     */
    public function finalize()
    {
        // make sure that its has not been finalized
        if ($this->_finalized) {
            return;
        }

        // set that we have finalized the image
        $this->_finalized = true;

        // prepare the result (write the left pixels)
        $this->_prepareResult();

        // negate the image when needed
        if ($this->getNegated()) {
            $this->_negate();
        }

        // apply a mask if the image mask shouldn't be read pixel by pixel
        if ($this->_isReadingPixelByPixel === false) {
            $this->_currentColor = null;
            $this->_applyMask();
        }
    }

    /**
     * Returns the string representation of the last used color
     *
     * @return string
     */
    public function getColorOfCurrentPixel()
    {
        return $this->_currentColor;
    }

    /**
     * Resolves the color component color space
     *
     * @param SetaPDF_Core_ColorSpace $colorSpace
     * @return null|\SetaPDF_Core_ColorSpace|\SetaPDF_Core_ColorSpace_DeviceCmyk|\SetaPDF_Core_ColorSpace_DeviceGray|\SetaPDF_Core_ColorSpace_DeviceRgb|\SetaPDF_Core_ColorSpace_IccBased|\SetaPDF_Core_ColorSpace_Separation|string
     * @throws \SetaPDF_Exception_NotImplemented
     */
    private function _resolveBaseColorSpace(SetaPDF_Core_ColorSpace $colorSpace)
    {
        if ($colorSpace instanceof \SetaPDF_Core_ColorSpace_IccBased) {
            // try to get the Alternate colorspace of the image
            $resultingColorSpace = $colorSpace->getAlternateColorSpace();

            // when the image has no alternate color space, we will create one
            // using the number of components used in the Icc Colorspace
            if ($resultingColorSpace === null) {
                switch ($colorSpace->getColorComponents()) {
                    case 1:
                        $resultingColorSpace = \SetaPDF_Core_ColorSpace_DeviceGray::create();
                        break;
                    case 3:
                        $resultingColorSpace = \SetaPDF_Core_ColorSpace_DeviceRgb::create();
                        break;
                    case 4:
                        $resultingColorSpace = \SetaPDF_Core_ColorSpace_DeviceCmyk::create();
                        break;
                    default:
                        throw new \SetaPDF_Exception_NotImplemented('Color space could not be resolved.');
                }
            }
        } elseif ($colorSpace instanceof \SetaPDF_Core_ColorSpace_Indexed) {
            // get the base color space
            $resultingColorSpace = $colorSpace->getBase();
        } else {
            // keep the color space as it is
            $resultingColorSpace = $colorSpace;
        }

        // when the colorspace that was resolved is still an indexed or an IccBased, do the same thing again
        if ($resultingColorSpace instanceof \SetaPDF_Core_ColorSpace_Indexed || $resultingColorSpace instanceof \SetaPDF_Core_ColorSpace_IccBased)
        {
            return $this->_resolveBaseColorSpace($resultingColorSpace);
        }

        // we have the base color space
        return $resultingColorSpace;
    }

    /**
     * Applies a decode array.
     *
     * @param int $key
     * @param int $color
     * @return int
     */
    protected function _applyDecodeArray(int $key, int $color): int
    {
        // calculate the resulting color
        $result = (int)($this->_decodeArray[$key]['min'] + ($color * $this->_decodeArray[$key]['calculated']));

        // make sure that its between 1 and 255
        if ($result < 0 ) {
            $result = 1;
        } elseif ($result > 255) {
            $result = 255;
        }

        return $result;
    }


    /**
     * Applies a decode array
     *
     * @return void
     */
    protected function _applyDecodeArrayNegate()
    {
        if (!\is_array($this->_decodeArray)) {
            return;
        }

        // apply simple Decode arrays for negation
        if (
            (count($this->_decodeArray) === 1 && $this->_decodeArray[0]['min'] === 1 && $this->_decodeArray[0]['max'] === 0)
            || (count($this->_decodeArray) === 3
                && $this->_decodeArray[0]['min'] === 1 && $this->_decodeArray[0]['max'] === 0
                && $this->_decodeArray[1]['min'] === 1 && $this->_decodeArray[1]['max'] === 0
                && $this->_decodeArray[2]['min'] === 1 && $this->_decodeArray[2]['max'] === 0
            )
            || (count($this->_decodeArray) === 4
                && $this->_decodeArray[0]['min'] === 1 && $this->_decodeArray[0]['max'] === 0
                && $this->_decodeArray[1]['min'] === 1 && $this->_decodeArray[1]['max'] === 0
                && $this->_decodeArray[2]['min'] === 1 && $this->_decodeArray[2]['max'] === 0
                && $this->_decodeArray[3]['min'] === 1 && $this->_decodeArray[3]['max'] === 0
            )
        ) {
            $this->setNegated(!$this->_negated);
        } else {
            throw new \SetaPDF_Exception_NotImplemented('Applying of Decode array is currently not supported.');
        }
    }

    /**
     * Returns the corresponding alpha value
     * @see MaskInterface::getCorrespondingAlphaValue()
     *
     * @param $x
     * @param $y
     * @param AbstractImage $caller
     * @return int
     */
    public function getCorrespondingAlphaValue($x, $y, AbstractImage $caller): int
    {
        return $this->getColor($x, $y)[0];
    }

    /**
     * Returns if the Mask is able to output a blob
     * @see MaskInterface::canOutputBlob()
     *
     * @return bool
     */
    public function canOutputBlob(): bool
    {
        return true;
    }

    /**
     * Returns the images width
     *
     * @return int
     */
    public function getWidth(): int
    {
        return $this->_width;
    }

    /**
     * Returns the images height
     *
     * @return int
     */
    public function getHeight(): int
    {
        return $this->_height;
    }

    /**
     * Returns the base color space
     *
     * @return \SetaPDF_Core_ColorSpace_DeviceCmyk|\SetaPDF_Core_ColorSpace_DeviceGray|\SetaPDF_Core_ColorSpace_DeviceRgb|
     */
    public function getBaseColorSpace()
    {
        return $this->_baseColorSpace;
    }

    /**
     * Returns the color space
     *
     * @return \SetaPDF_Core_ColorSpace
     */
    public function getColorSpace(): \SetaPDF_Core_ColorSpace
    {
        return $this->_colorSpace;
    }

    public function setNegated(bool $value)
    {
        $this->_negated = $value;
    }

    public function getNegated(): bool
    {
        return $this->_negated;
    }

    /**
     * Returns if the instance can read a specific type of blob
     *
     * @param string $imageType
     * @return bool
     */
    abstract public function canRead(string $imageType): bool;

    /**
     * Writes a pixel into the image
     *
     * @param string $color
     * @return void
     */
    abstract public function writePixel(string $color): void;

    /**
     * Returns a new color
     *
     * @param string $color
     * @param null|int $alphaValue
     * @return mixed
     */
    abstract protected function _createColor(string $color, ?int $alphaValue);

    /**
     * Reads a blob
     *
     * @param string $imageBlob
     * @return void
     */
    abstract protected function _readBlob(string $imageBlob): void;

    /**
     * Applies a mask
     * can be slow because some masks need to iterate through each pixel
     *
     * @return void
     */
    abstract protected function _applyMask(): void;

    /**
     * Negates an image
     *
     * @return void
     */
    abstract protected function _negate(): void;

    /**
     * Returns the resulting instance
     *
     * @return mixed
     */
    abstract public function getResult();

    /**
     * Prepares the result
     *
     * @return void
     */
    abstract protected function _prepareResult(): void;

    /**
     * Gets called on the mask instance and destroys left parts
     *
     * @return void
     */
    abstract protected function _cleanUp(): void;

    /**
     * Returns the color on $x and $y
     *
     *  RGB
     *   - [50, 50, 50]
     *  CMYK
     *   - [50, 50, 50, 50]
     *  ...
     *
     * @param int $x
     * @param int $y
     * @return array
     */
    abstract public function getColor(int $x, int $y): array;
}