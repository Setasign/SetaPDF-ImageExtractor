# SetaPDF-ImageExtractor
This package is a proof of concept to extract images from PDFs in pure PHP with the SetaPDF-Core component and an image render module.
Currently, not all kinds of images are supported. This is also very dependent on your used image renderer library.
GD and Imagick are currently implemented. We recommend you to use Imagick as it's more feature complete as GD but may be
a little harder to install.

## Requirements
This package is developed and tested on PHP >= 7.4. 

You'll need an active license of the [SetaPDF-Core](https://www.setasign.com/products/setapdf-core/details/) component.

Requirements of the SetaPDF-Core component can be found 
[here](https://manuals.setasign.com/setapdf-core-manual/installation/#index-1).

## Usage
This example here extracts all images from the first page of the pdf: 

```php
$document = SetaPDF_Core_Document::loadByFilename('your-pdf-file.pdf');
$images = \setasign\SetaPDF\ImageExtractor\ImageExtractor::getImagesByPageNo($document, 1);
foreach ($images as $imageData) {
    // if you're using imagick
    $imagick = \setasign\SetaPDF\ImageExtractor\ImageExtractor::toImage($imageData, ImageExtractor::IMAGE_RENDERER_IMAGICK);
    $imagick->setImageFormat('png');
    $image = $imagick->getImageBlob();
    $imagick->destroy();
    echo '<img src="data:image/png;base64,' . base64_encode($image) . '"/>';

    // if you're using gd use this code instead
    $gd = ImageExtractor::toImage($imageData, ImageExtractor::IMAGE_RENDERER_GD);
    ob_start();
    imagepng($gd);
    $image = ob_get_clean();
    imagedestroy($gd);
    echo '<img src="data:image/png;base64,' . base64_encode($image) . '"/>';
}
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
