& node (Join-Path $PSScriptRoot 'build-css.js')
if ($LASTEXITCODE -ne 0) { throw 'CSS build failed.' }
