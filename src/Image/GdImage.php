<?php

declare(strict_types=1);

namespace setasign\SetaPDF\ImageExtractor\Image;

/**
 * Class GdImage
 *
 * This class is used to convert an image in a pdf to a regular image.
 * It uses gd to create a new image.
 */
class GdImage extends AbstractImage
{
    /**
     * The gd resource
     *
     * @var resource
     */
    protected $_image;

    public function __construct(
        int $width,
        int $height,
        \SetaPDF_Core_ColorSpace $colorSpace,
        ?array $decodeArray,
        MaskInterface $mask = null
    ) {
        if (!\extension_loaded('gd')) {
            throw new \Exception('GD is not installed.');
        }

        parent::__construct($width, $height, $colorSpace, $decodeArray, $mask);
    }

    /**
     * Returns if an image blob can be read by gd
     *
     * @param string $imageType
     * @return bool
     */
    public function canRead(string $imageType): bool
    {
        if ($imageType !== 'DCTDecode') {
            return false;
        }

        if (
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceRgb &&
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceGray &&
            !$this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_IccBased
        ) {
            return false;
        }

        if ($this->_colorSpace instanceof \SetaPDF_Core_ColorSpace_IccBased) {
            if ($this->_colorSpace->getColorComponents() > 3) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads an blob
     *
     * @param string $imageBlob
     * @throws \Exception
     */
    protected function _readBlob(string $imageBlob): void
    {
        // read the blob
        $_image = imagecreatefromstring($imageBlob);

        // make sure that nothing went wrong
        if ($_image === false) {
            throw new \Exception('Image could not be created from ' . get_class($this));
        }

        $this->_applyDecodeArrayNegate();

        // store the image resource
        $this->_image = $_image;
    }

    /**
     * Adds a pixel to the image,
     * note then you need to call self::_getColor() to get the corresponding color from $color
     *
     * @param string $color
     */
    public function writePixel(string $color): void
    {
        imagesetpixel($this->getImage(), $this->_x, $this->_y, $this->_getColor($color));

        $this->_x++;
        if ($this->_x == $this->_width) {
            $this->_x = 0;
            $this->_y++;
        }
    }

    /**
     * Creates a new color in rgb format and returns the color identifier
     *
     * @param string $color
     * @param null|int $alphaValue
     * @return int
     * @throws \SetaPDF_Exception_NotImplemented
     */
    protected function _createColor(string $color, ?int $alphaValue): int
    {
        if ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceGray) {
            // color conversion (Greyscale to RGB)
            $r = $g = $b = ord($color);

        } elseif ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceRgb) {
            // its already rgb, keep the colors as they are
            $r = ord($color[0]);
            $g = ord($color[1]);
            $b = ord($color[2]);
        } elseif ($this->_baseColorSpace instanceof \SetaPDF_Core_ColorSpace_DeviceCmyk) {
            // color conversion (CMYK to RGB)

            $colorArr = [];
            for ($i = 0; $i < 4; $i++) {
                $colorArr[$i] = ord($color[$i]) / 255;
            }

            $r = (int) ((1 - ($colorArr[0] * (1 - $colorArr[3]) + $colorArr[3])) * 255);
            $g = (int) ((1 - ($colorArr[1] * (1 - $colorArr[3]) + $colorArr[3])) * 255);
            $b = (int) ((1 - ($colorArr[2] * (1 - $colorArr[3]) + $colorArr[3])) * 255);
        } else {
            // unknown color
            throw new \SetaPDF_Exception_NotImplemented(
                'Unsupported color space in GD image: ' . $this->_baseColorSpace->getFamily()
            );
        }

        // create a new gd color
        if ($this->_mask === null) {
            // create a color without alpha
            $resultingColor = imagecolorallocate($this->getImage(), $r, $g, $b);
        } else {
            // create a color with alpha, but translate the alpha value
            $alphaValue = (int) (127 - (($alphaValue / 255) * 127));
            $resultingColor = imagecolorallocatealpha($this->getImage(), $r, $g, $b, $alphaValue);
        }

        // return the color identifier
        return $resultingColor;
    }

    /**
     * Applies a mask to a image
     * this can take a while, because gd needs to iterate the image pixel by pixel
     */
    protected function _applyMask(): void
    {
        // make sure that we have a mask
        if ($this->_mask === null) {
            return;
        }

        /*
         * We need to create a new image because we need to split up all the colors (due to the alpha)
         */
        $resultingImage = imagecreatetruecolor($this->_width, $this->_height);

        // set up an alpha channel for the image
        imagealphablending($resultingImage, false);
        imagesavealpha($resultingImage, true);

        // array to store all created colors
        $colors = [];

        // iterate through the whole image
        for ($y = 0; $y < $this->_height; $y++) {
            for ($x = 0; $x < $this->_width; $x++) {

                // get the color identifier for the pixel
                $colorIndex = imagecolorat($this->getImage(), $x, $y);

                // get the alpha value
                $alphaValue = $this->_mask->getCorrespondingAlphaValue($x, $y, $this);

                // create a key to store the colors
                $colorKey = $colorIndex . '#' . $alphaValue;

                // check if the color already was created
                if (!isset($colors[$colorKey])) {
                    // get the real color values
                    $color = imagecolorsforindex($this->getImage(), $colorIndex);

                    // translate the alpha value
                    $alphaValue = (int) (127 - (($alphaValue / 255) * 127));

                    // create a new color with alpha value
                    $colors[$colorKey] = imagecolorallocatealpha(
                        $resultingImage,
                        $color['red'],
                        $color['green'],
                        $color['blue'],
                        $alphaValue
                    );
                }

                // write the new color in the new image
                imagesetpixel($resultingImage, $x, $y, $colors[$colorKey]);
            }
        }

        // when we have finished creating the image, we will destroy the old one and override it with the new one
        imagedestroy($this->_image);
        unset($this->_image);
        $this->_image = $resultingImage;
    }

    /**
     * Does nothing, because gd cant do mutch more with the image
     *
     * @return void
     */
    protected function _prepareResult(): void
    {
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
        return array_values(imagecolorsforindex($this->_image, imagecolorat($this->getImage(), $x, $y)));
    }


    /**
     * Gets called on the mask instance and destroys left parts
     *
     * @return void
     */
    protected function _cleanUp(): void
    {
        imagedestroy($this->_image);
    }

    /**
     * Returns if the Image should read the MaskInterface pixel by pixel or if it should get the stream afterwards
     *
     * @return bool
     */
    public function isReadingPixelByPixel(): bool
    {
        return true;
    }

    /**
     * Negates an image
     *
     * @return void
     */
    protected function _negate(): void
    {
        imagefilter($this->getImage(), IMG_FILTER_NEGATE);
    }

    /**
     * Returns the resulting image instance
     *
     * @return resource
     */
    public function getResult()
    {
        return $this->getImage();
    }

    /**
     * Returns a blob representation of the image
     *
     * @return string
     */
    public function getBlob(): string
    {
        ob_start();
        imagejpeg($this->_image);
        $result = ob_get_contents();
        ob_end_clean();

        return $result;
    }

    /**
     * Gets called to get the image instance
     * if there is no image instance available, will it create a new one
     *
     * @return resource
     */
    private function getImage()
    {
        if ($this->_image === null) {
            $this->_image = imagecreatetruecolor($this->_width, $this->_height);

            if ($this->_mask !== null) {
                imagealphablending($this->_image, false);
                imagesavealpha($this->_image, true);
            }
        }

        return $this->_image;
    }
}
