<?php

class ThumbnailExtractor {
    private string $tempDir;

    public function __construct($tempDir = null) {
        $this->tempDir = $tempDir ?? (__DIR__ . DIRECTORY_SEPARATOR . 'temp_thumbs');
        if (!file_exists($this->tempDir)) mkdir($this->tempDir, 0777, true);
    }

    public function extractBestThumbnailSmart($videoPath, $outputPath, $numThumbnails = 15): bool {
        // Vérifier si FFmpeg est installé
        exec('ffmpeg -version', $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \Exception('FFmpeg n\'est pas installé sur ce système.');
        }

        // Vérifier si le fichier vidéo existe
        if (!file_exists($videoPath)) {
            throw new \Exception('Le fichier vidéo n\'existe pas.');
        }

        // Obtenir la durée de la vidéo
        $command = "ffprobe -v error -show_entries format=duration -of csv=p=0 \"$videoPath\"";
        $duration = floatval(trim(shell_exec($command)));
        if (!$duration || !is_numeric($duration)) {
            throw new \Exception('Impossible de déterminer la durée de la vidéo. Le fichier pourrait être corrompu.');
        }

        $interval = $duration / ($numThumbnails + 1); // +1 pour éviter tout début/fin
        $bestScore = -INF;
        $bestThumb = null;
        $tempThumbs = [];

        // Nettoyer le dossier temp avant de lancer
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*.jpg') as $file) {
            @unlink($file);
        }

        for ($i = 1; $i <= $numThumbnails; $i++) {
            $timestamp = $i * $interval;
            $tempPath = $this->tempDir . DIRECTORY_SEPARATOR . 'thumb_' . uniqid() . '.jpg';
            // Extraire la vignette
            $cmd = sprintf(
                'ffmpeg -ss %.2f -i "%s" -vframes 1 -q:v 2 "%s" -y -loglevel error',
                $timestamp,
                $videoPath,
                $tempPath
            );
            shell_exec($cmd);
            if (file_exists($tempPath)) {
                $score = $this->evaluateImageSmart($tempPath);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestThumb = $tempPath;
                }
                $tempThumbs[] = $tempPath;
            }
        }
        // Copier la meilleure vignette vers $outputPath
        if ($bestThumb) {
            copy($bestThumb, $outputPath);
        }
        // Nettoyer les vignettes temporaires
        foreach ($tempThumbs as $thumb) {
            if (file_exists($thumb)) {
                unlink($thumb);
            }
        }
        return file_exists($outputPath);
    }

    private function evaluateImageSmart($imagePath): float {
        $im = imagecreatefromjpeg($imagePath);
        if (!$im) return -INF;
        $w = imagesx($im);
        $h = imagesy($im);
        $sampleStep = 4;
        $brightness = 0;
        $sharpness = 0;
        $darkPixels = 0;
        $brightPixels = 0;
        $total = 0;
        for ($y = 1; $y < $h - 1; $y += $sampleStep) {
            for ($x = 1; $x < $w - 1; $x += $sampleStep) {
                $gray = $this->brightnessAt($im, $x, $y);
                $brightness += $gray;
                $total++;
                if ($gray < 30) $darkPixels++;
                if ($gray > 230) $brightPixels++;
                // Netteté (laplacien simplifié)
                $c = $gray;
                $cx = $this->brightnessAt($im, $x - 1, $y) + $this->brightnessAt($im, $x + 1, $y);
                $cy = $this->brightnessAt($im, $x, $y - 1) + $this->brightnessAt($im, $x, $y + 1);
                $lap = abs(4 * $c - $cx - $cy);
                $sharpness += $lap;
            }
        }
        imagedestroy($im);
        if ($total == 0) return -INF;
        $brightnessAvg = $brightness / $total;
        $sharpnessAvg = $sharpness / $total;
        $darkRatio = $darkPixels / $total;
        $brightRatio = $brightPixels / $total;
        // Normalisation basique
        $b = $brightnessAvg / 255;
        $s = min($sharpnessAvg / 200, 1.0);
        if ($sharpnessAvg < 1) return -INF;
        return (0.3 * $b) + (0.6 * $s) - (0.05 * $darkRatio) - (0.05 * $brightRatio);
    }

    private function brightnessAt($im, $x, $y): float {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return 0.299*$r + 0.587*$g + 0.114*$b;
    }
}
