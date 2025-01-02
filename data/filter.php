<?php
// File paths
$inputFile = 'stocks.txt';
$outputFile = 'filtered_stocks.txt';

// Open the input file for reading
if (!file_exists($inputFile)) {
    die("Input file does not exist.\n");
}

$inputData = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Array to store tickers
$tickers = [];

// Process each line of the input file
foreach ($inputData as $line) {
    // Split the line by tabs or spaces and extract the second column (ticker)
    $columns = preg_split('/\s+/', $line);
    if (isset($columns[1]) && is_numeric($columns[1])) {
        $tickers[] = $columns[1] . '.T'; // Append ".T" to the ticker
    }
}

// Shuffle the tickers
shuffle($tickers);

// Open the output file for writing
$outputData = fopen($outputFile, 'w');
if (!$outputData) {
    die("Could not open output file for writing.\n");
}

// Write the shuffled tickers to the output file
foreach ($tickers as $ticker) {
    fwrite($outputData, $ticker . PHP_EOL);
}

// Close the output file
fclose($outputData);

echo "Shuffled tickers with '.T' suffix have been successfully written to $outputFile.\n";
