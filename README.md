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
} elseif ($scannerResult->isUnknown()) {
    echo 'The archive could not be assessed completely (encrypted, split or damaged).';
} else {
    echo 'File is clean';
}
```

### Result states and evidence

Every scan returns one of three states:

* `BombStatus::Bomb` - a bomb pattern was confirmed (`isBomb()` returns `true`)
* `BombStatus::Unknown` - the input could not be assessed completely, for
  example an encrypted RAR entry, an entry that crosses a multi-volume
  boundary, an entry with zero packed size or a damaged volume
* `BombStatus::Safe` - the input was inspected and no bomb was found

Each finding is represented by a `BombEvidence` value object with a stable,
deterministic identity of the form `engine:entry`, for example
`rar:entry#3:Desktop/10GB` or `rar:archive#total`. The identity does not
depend on the other registered engines and stays identical across repeated
scans. Use `getEvidence()`, `getBombEvidence()` and `getUnknownEvidence()`
to explain which engine and entry produced a verdict.

### RAR scanning rules

The RAR engine no longer evaluates every entry against an isolated
unpacked/packed ratio. Instead all entries share one total expansion budget:
the sum of all unpacked entry sizes is compared with the archive size
multiplied by the configured ratio. Entries that cannot be evaluated
(encrypted, volume spanning or zero packed size) make the verdict `Unknown`
instead of silently passing.

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
