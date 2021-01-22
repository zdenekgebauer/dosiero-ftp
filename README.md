# Dosiero PHP FTP storage

FTP storage for Dosiero PHP server connector https://github.com/zdenekgebauer/dosiero-php
Supports 

## Installation
with composer 
```bash
composer require zdenekgebauer/dosiero-ftp
```

## Usage
1. in your entry point file (see README https://github.com/zdenekgebauer/dosiero-php/)    
```php
// create storage
$ftpStorage = new FtpStorage('FTP');
$ftpStorage->setOption(Storage::OPTION_MODE_DIRECTORY, 0755);
$ftpStorage->setOption(Storage::OPTION_MODE_FILE, 0644);
$ftpStorage->setOption(FtpStorage::OPTION_HOST, '127.0.0.1');
$ftpStorage->setOption(FtpStorage::OPTION_LOGIN, 'anonymous');
$ftpStorage->setOption(FtpStorage::OPTION_PASSWORD, '');
$ftpStorage->setOption(FtpStorage::OPTION_SSL, false);
$ftpStorage->setOption(FtpStorage::OPTION_BASE_PATH, '/'); 
$ftpStorage->setOption(FtpStorage::OPTION_CACHE_DIRECTORY , __DIR__  . '/ftpcache/');

// add storage to connector
$connector->addStorage($ftpStorage);
```
