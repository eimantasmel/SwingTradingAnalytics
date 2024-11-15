<?php

// Specify the input and output file paths
$inputFilePath = './forex.txt';
$outputFilePath = './forex2.txt';

// Open the input file for reading and output file for writing
$inputFile = fopen($inputFilePath, 'r');
$outputFile = fopen($outputFilePath, 'w');

if ($inputFile && $outputFile) {
    while (($line = fgets($inputFile)) !== false) {
        // Remove the slash and any surrounding whitespace or newline characters
        $cleanedLine = str_replace('/', '', trim($line));
        
        // Write the cleaned result to the output file with a newline
        fwrite($outputFile, $cleanedLine . PHP_EOL);
    }
    fclose($inputFile);
    fclose($outputFile);
    echo "Data has been written to $outputFilePath successfully.";
} else {
    echo "Error: Unable to open the file(s).";
}
