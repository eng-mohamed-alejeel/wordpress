$cssDir = "C:\xampp\htdocs\wordpress\wp-content\themes\car-dealer\assets\css"
$files = @(
  "abstracts/_variables.css",
  "base/_reset.css",
  "base/_typography.css",
  "components/_buttons.css",
  "components/_cards.css",
  "components/_forms.css",
  "components/_icons.css",
  "components/_floating.css",
  "layout/_header.css",
  "layout/_footer.css",
  "layout/_grid.css",
  "pages/_home.css",
  "pages/_cars.css",
  "pages/_account.css",
  "pages/_admin.css",
  "pages/_page.css",
  "themes/_auto-brands.css"
)
$output = ""
foreach ($file in $files) {
  $path = Join-Path $cssDir $file
  if (Test-Path $path) {
    $content = Get-Content $path -Raw
    $output += $content + "`n"
  }
}
Set-Content -Path (Join-Path $cssDir "main.css") -Value $output -Encoding UTF8
Write-Host "Done"
