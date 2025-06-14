<?php

require_once 'extract_thumbnail.php';

// Vérifier si un argument a été fourni
if ($argc < 2) {
    die("Usage: php index.php <chemin_vers_video>\n");
}

$videoPath = $argv[1];

// Vérifier si le fichier existe
if (!file_exists($videoPath)) {
    die("Erreur BEVATA: Le fichier '$videoPath' n'existe pas.\n");
}

$outputPath = pathinfo($videoPath, PATHINFO_FILENAME) . '_thumbnail.jpg';

try {
    if (extractBestThumbnailSmart($videoPath, $outputPath, 10)) {
        echo "Vignette extraite avec succès : $outputPath\n";
    } else {
        echo "Erreur lors de l'extraction de la vignette.\n";
    }
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}
