<?php

namespace setasign\SetaPDF\ImageExtractor\Image;

/**
 * This class is used to filter specific colors into transparent colors
 *
 * Class MaskArray
 * @package setasign\SetaPDF\Demos\Core\ExtractImage\Image
 */
class MaskArray implements MaskInterface
{
    /**
     * The color that will be made transparent
     *
     * @var array
     */
    protected $_colors = array();

    /**
     * The amount of colors
     *
     * @var int
     */
    protected $_colorLength = 0;

    /**
     * a flag if the MaskArray should try to get the current color or directly get the color
     *
     * @var bool
     */
    protected $_tryingToGetCurrentColor = true;

    /**
     * MaskArray constructor.
     * @param array $maskArray
     */
    public function __construct(array $maskArray)
    {
        $this->_colors = $maskArray;
        for ($i = 0; $i < $this->_colorLength; $i += 2) {
            $var0 = $this->_colors[$i];
            $var1 = $this->_colors[$i + 1];

            $this->_colors[$i] = min($var0, $var1);
            $this->_colors[$i + 1] = max($var0, $var1);
        }

        $this->_colorLength = count($this->_colors);
    }

    /**
     * Cleans the instance
     *
     * @return void
     */
    public function cleanUp()
    {
        unset($this->_colors);
    }

    /**
     * Returns if the AbstractImage should read the MaskInterface pixel by pixel or if it should get the stream afterwards
     *
     * @return bool
     */
    public function isReadingPixelByPixel(): bool
    {
        return true;
    }

    /**
     * Returns if the Mask is able to output a blob
     *
     * @return bool
     */
    public function canOutputBlob(): bool
    {
        return false;
    }

    /**
     * Returns the image blob as a string
     *
     * @param AbstractImage $caller
     * @throws \BadFunctionCallException
     * @return string
     */
    public function getBlob(AbstractImage $caller): string
    {
        throw new \BadFunctionCallException();
    }

    /**
     * Returns the corresponding alpha value
     *
     * @param int $x
     * @param int $y
     * @param AbstractImage $caller
     * @throws \SetaPDF_Exception_NotImplemented
     * @return int
     */
    public function getCorrespondingAlphaValue($x, $y, AbstractImage $caller): int
    {
        if ($this->_tryingToGetCurrentColor) {
            $currentColor = $caller->getColorOfCurrentPixel();

            if ($currentColor === null) {
                $currentColor = $caller->getColor($x, $y);
                $this->_tryingToGetCurrentColor = false;
            }
        } else {
            $currentColor = $caller->getColor($x, $y);
        }


        if ($caller->getColorSpace() instanceof \SetaPDF_Core_ColorSpace_Indexed) {
            $color = ord($currentColor);

            for ($i = 0; $i < $this->_colorLength; $i += 2) {
                if (
                    $this->_colors[$i] >=  $color &&
                    $this->_colors[$i + 1] <= $color
                ) {
                    return 0;
                }
            }
        } else {
            throw new \SetaPDF_Exception_NotImplemented('The Mask array currently only handles indexed images');
        }

        return 255;
    }
}