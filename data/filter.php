<?php
// File paths
$inputFile = __DIR__ . '/stocks.txt';
$outputFile = __DIR__ . '/output.txt';

// Check if input file exists
if (!file_exists($inputFile)) {
    die("Input file 'stocks.txt' not found.\n");
}

// Read the input file content into an array
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    die("Failed to read input file.\n");
}

$tickers = [];

// Process each line to extract the second column
foreach ($lines as $line) {
    $columns = explode("\t", trim($line)); // Split line into columns
    if (isset($columns[1])) {
        $tickers[] = $columns[1] . '.SS'; // Append '.SS' to the second column
    }
}

// Shuffle the tickers array
shuffle($tickers);

// Write the shuffled tickers to the output file
if (file_put_contents($outputFile, implode(PHP_EOL, $tickers) . PHP_EOL) === false) {
    die("Failed to write to output file.\n");
}

echo "Processed and shuffled successfully. Results saved in 'output.txt'.\n";
