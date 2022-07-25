<?php

declare(strict_types=1);

use setasign\SetaPDF\ImageExtractor\ImageExtractor;
use setasign\SetaPDF\ImageExtractor\ImageProcessor;

set_time_limit(180);

require_once __DIR__ . '/../vendor/autoload.php';

$folders = array_filter([
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/images/pdfs/') . '/*.pdf',
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/') . '/*.pdf',
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/xObjects/') . '/*.pdf',
    realpath(__DIR__ . '/../../SetaPDF/tests/unit/SetaPDF/Core/_files/customers/') . '/*/*.pdf',
    realpath(__DIR__ . '/../files/') . '/*.pdf',
    realpath(__DIR__ . '/../files/') . '/*.PDF'
]);

$files = [];
foreach ($folders as $folder) {
    foreach (glob($folder) as $path) {
        $files[$path] = $path;
    }
}

if (!isset($_GET['f']) || !isset($files[$_GET['f']])) {
    foreach ($files as $path) {
        echo '<a href="test.php?f=' . urlencode($path) . '">' . htmlspecialchars($path) . '</a><br/>';
    }
    die();
}

$directory = 'extractedImages/';

$totalTimeIm = 0;
$totalTimeGD = 0;

$document = SetaPDF_Core_Document::loadByFilename($_GET['f']);
$pageCount = $document->getCatalog()->getPages()->count();

echo '<table bgcolor="#adff2f" border="1"><th><tr><td>Image data</td><td>Output GD</td><td>GD image</td><td>Output IM</td><td>IM image</td></tr></th>';

$imageCount = 0;
$totalStartTime = microtime(true);

$skipMask = false;

for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
    $images = ImageExtractor::getImagesByPageNo($document, $pageNo);

    foreach ($images as $imageData) {
        if ($skipMask && $imageData['mask'] ?? false) {
            continue;
        }

        if ($imageData['type'] === 'xObject') {
            /**
             * @var $xObject SetaPDF_Core_XObject_Image
             */
            $xObject = $imageData['xObject'];
            $xObjectId = $xObject->getIndirectObject()->getObjectId();
            $printableImageData = $imageData;
            $printableImageData['xObject'] = [
                'id' => $xObjectId,
                'gen' => $xObject->getIndirectObject()->getGen()
            ];
        } else {
            $printableImageData = $imageData;
            unset($printableImageData['stream']);
        }

        $imageCount++;

        echo '<tr><td colspan="6">memory: ' . memory_get_usage() . '</td></tr>';

        $im = null;
        $gd = null;
        $image = null;

        echo '<tr>';
        echo '<td><pre>' . var_export($printableImageData, true) . '</pre></td>';
        echo '<td>';
        try {
            $startTime = microtime(true);
            $gd = ImageExtractor::toImage($imageData, ImageExtractor::GD);
            $timeNeeded = (microtime(true) - $startTime);
            $totalTimeGD += $timeNeeded;
            echo 'finished in: ' . $timeNeeded;

            ob_start();
            imagepng($gd);
            $image = ob_get_clean();
            imagedestroy($gd);
            unset($gd);
        } catch (Exception $e) {
            echo $e;
        }

        echo '</td><td>';
        if ($image) {
            echo '<img src="data:image/png;base64,' . base64_encode($image) . '"/>';
        }
        echo '</td><td>';

        $image = null;
        try {
            $startTime = microtime(true);
            $im = ImageExtractor::toImage($imageData, ImageExtractor::IMAGICK);
            $timeNeeded = (microtime(true) - $startTime);
            $totalTimeIm += $timeNeeded;
            echo 'finished in: ' . $timeNeeded;
            $im->setImageFormat('png');
            $image = $im->getImageBlob();
            $im->destroy();
            unset($im);
        } catch (Throwable $e) {
            echo $e->getMessage();
        }

        echo '</td><td>';
        if ($image) {
            echo '<img src="data:image/png;base64,' . base64_encode($image) . '"/>';
        }
        echo '</td>';

        // extra information
        echo '<td>' . $pageNo . '</td>';
        echo '<td>';
        if ($imageData['type'] === 'xObject') {
            echo $xObjectId;
        }
        echo '</td>';

        echo '<td>';
        echo '<pre>';
        if ($imageData['type'] === 'xObject') {
            var_dump($xObject->getIndirectObject()->ensure()->getValue()->toPhp());
        } else {
            var_dump($imageData['stream']->getValue()->toPhp());
        }
        echo '</pre>';
        echo '</td>';

//        try {
//            echo '<td>' . $xObject->getColorSpace()->getFamily() . '</td>';
//        } catch (Throwable $e) {
//        }

        echo '</tr>';
    }
}
echo '</table>';
