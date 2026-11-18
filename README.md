# selective/archive-bomb-scanner

ZIP and PNG bomb scanner for PHP.

[![Latest Version on Packagist](https://img.shields.io/github/release/selective-php/archive-bomb-scanner.svg?style=flat-square)](https://packagist.org/packages/selective/archive-bomb-scanner)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/selective/archive-bomb-scanner.svg?style=flat-square)](https://packagist.org/packages/selective/archive-bomb-scanner/stats)

## Features

* Detection of ZIP archive bombs
* Detection of RAR archive bombs
* Detection of PNG bombs
* Content based (magic number) detection of renamed nested archives
* Explicit status for disguised, truncated and unknown nested formats
* No dependencies
* Very fast

## Requirements

* PHP 8.1 - 8.5

## Installation

```
composer require selective/archive-bomb-scanner
```

## Usage

### Scan ZIP file

```php
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use SplFileObject;

$file = new SplFileObject('42.zip');

$scanner = new BombScanner();
$scanner->addEngine(new ZipBombEngine());

$scannerResult = $scanner->scanFile($file);

if ($scannerResult->isBomb()) {
    echo 'Archive bomb detected!';
} else {
    echo 'File is clean';
}
```

### Scan in-memory ZIP file

```php
use Selective\ArchiveBomb\BombScanner;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use SplTempFileObject;

$file = new SplTempFileObject();

$file->fwrite('my file content');

$scanner = new BombScanner();
$scanner->addEngine(new ZipBombEngine());

$isBomb = $detector->scanFile($file)->isBomb(); // true or false
```

### Scan RAR file

```php
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Engine\RarBombEngine;
use SplFileObject;

$file = new SplFileObject('10GB.rar');

$scanner = new BombScanner();
$scanner->addEngine(new RarBombEngine());

$scannerResult = $scanner->scanFile($file);

if ($scannerResult->isBomb()) {
    echo 'Archive bomb detected!';
} else {
    echo 'File is clean';
}
```

### Scan PNG file

```php
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Engine\PngBombEngine;
use SplFileObject;

$file = new SplFileObject('example.png');

$scanner = new BombScanner();
$scanner->addEngine(new PngBombEngine());

$scannerResult = $scanner->scanFile($file);

if ($scannerResult->isBomb()) {
    echo 'PNG bomb detected!';
} else {
    echo 'File is clean';
}
```

### Scan renamed nested archives

Nested members are identified by their content magic numbers, so an uploaded
ZIP that contains e.g. `payload.txt` (which is in fact another ZIP) is still
recursed into. The container id is preserved on every result and nested entry,
and non-parseable members get an explicit status instead of being treated as
plain files:

```php
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use SplFileObject;

$scanner = new BombScanner();
$scanner->addEngine(new ZipBombEngine());

// The second argument is the upload/container id from the gateway.
$result = $scanner->scanFile(new SplFileObject('upload.zip'), 'container-123');

echo $result->getType()->getId(); // "zip"
echo $result->getStatus()->value; // "archive_scanned"
echo $result->getContainerId(); // "container-123"

foreach ($result->getNestedEntries() as $entry) {
    echo $entry->getName(); // member name, extension may be faked
    echo $entry->getType()->getId(); // format detected from magic numbers
    echo $entry->getStatus()->value; // archive_scanned, truncated, ...
    echo $entry->getContainerId(); // stays "container-123"
}
```

Nested member statuses:

* `archive_scanned`: a supported container (e.g. ZIP) that was parsed recursively
* `truncated`: an archive signature without the required structural records
* `unsupported_archive`: a recognised container (e.g. RAR, 7-Zip) this engine cannot parse
* `unknown_format`: no recognised signature, e.g. a faked `.zip` name on plain data
* `regular_file`: ordinary non-archive content, reported only on the top-level result

The verifiable fixture runner covers all of these cases:

```
php tests/build_nested_fixtures.php
php tests/run.php
```

## License

MIT
