# selective/archive-bomb-scanner

ZIP and PNG bomb scanner for PHP.

[![Latest Version on Packagist](https://img.shields.io/github/release/selective-php/archive-bomb-scanner.svg?style=flat-square)](https://packagist.org/packages/selective/archive-bomb-scanner)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/selective/archive-bomb-scanner.svg?style=flat-square)](https://packagist.org/packages/selective/archive-bomb-scanner/stats)

## Features

* Detection of ZIP archive bombs
* Detection of RAR archive bombs
* Detection of PNG bombs
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

### Scan result status

The sizes declared inside a ZIP archive are attacker controlled, so they are
only used as budget hints. The scanner verifies every member against its actual
data stream and reports one of three explicit statuses:

```php
$scannerResult = $scanner->scanFile($file);

$scannerResult->isBomb();    // true only when bomb evidence was verified
$scannerResult->getStatus(); // "safe", "unknown" or "bomb"
$scannerResult->isSafe();    // true only when every member was fully verified
$scannerResult->isUnknown(); // true when members cannot be fully inspected
$scannerResult->getEngineName();
$scannerResult->getReason();
```

A member that is encrypted, uses an unsupported compression method, has a
method conflict, a CRC/size mismatch or a truncated or otherwise corrupted
stream cannot be verified. Such an archive is reported as `unknown` instead of
being silently treated as safe, while archives that genuinely expand beyond the
configured ratio are still detected as bombs.

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

## License

MIT
