$serverPath = 'C:\xampp\mariadb-modern\mariadb-10.11.19-winx64\bin\mysqld.exe'
$serverConfig = 'C:/xampp/mariadb-modern/site-data/my.ini'
if (-not (Test-Path -LiteralPath $serverPath)) { throw 'The local MariaDB installation is missing.' }
$listener = Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue
if (-not $listener) {
    Start-Process -FilePath $serverPath -ArgumentList "--defaults-file=$serverConfig", '--bind-address=127.0.0.1' -WindowStyle Hidden
}
