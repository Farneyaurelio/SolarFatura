[CmdletBinding()]
param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'SolarFatura\tools'),
    [switch]$NonInteractive,
    [string]$LogPath
)

$ErrorActionPreference = 'Stop'
$apiUrl = 'https://api.github.com/repos/oschwartz10612/poppler-windows/releases/latest'

function Write-InstallStatus([string]$Message) {
    Write-Host $Message
    if ($LogPath) {
        $logDirectory = Split-Path -Parent $LogPath
        if ($logDirectory) { New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null }
        Add-Content -LiteralPath $LogPath -Value ('[' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '] ' + $Message)
    }
}

Write-Host 'SolarFatura - instalação do leitor de PDFs Poppler' -ForegroundColor Green
Write-Host 'O script baixará o pacote oficial publicado no GitHub e configurará apenas o seu usuário do Windows.'
if (-not $NonInteractive -and (Read-Host 'Deseja continuar? (S/N)') -notmatch '^[sS]$') {
    Write-Host 'Instalação cancelada.'
    exit 0
}

$release = Invoke-RestMethod -Uri $apiUrl -Headers @{ 'User-Agent' = 'SolarFatura-Installer' }
$asset = $release.assets | Where-Object { $_.name -match '^Release-.*\.zip$' } | Select-Object -First 1
if (-not $asset) { throw 'O pacote ZIP do Poppler não foi encontrado na release oficial.' }

$assetUri = [Uri]$asset.browser_download_url
if ($assetUri.Scheme -ne 'https' -or $assetUri.Host -ne 'github.com') {
    throw 'A URL de download recebida não pertence ao GitHub seguro.'
}

$installDir = Join-Path $InstallRoot ('poppler-' + $release.tag_name.TrimStart('v'))
$pdfToText = Join-Path $installDir 'Library\bin\pdftotext.exe'
if (-not (Test-Path -LiteralPath $pdfToText)) {
    New-Item -ItemType Directory -Path $installDir -Force | Out-Null
    $zipPath = Join-Path $env:TEMP ('SolarFatura-Poppler-' + $release.tag_name + '.zip')
    try {
        Write-InstallStatus "Baixando Poppler $($release.tag_name)..."
        Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $zipPath
        Write-InstallStatus 'Extraindo arquivos...'
        Expand-Archive -LiteralPath $zipPath -DestinationPath $installDir -Force
    } finally {
        Remove-Item -LiteralPath $zipPath -Force -ErrorAction SilentlyContinue
    }
}

if (-not (Test-Path -LiteralPath $pdfToText)) {
    throw "O arquivo pdftotext.exe não foi encontrado em $installDir após a extração."
}

[Environment]::SetEnvironmentVariable('SOLARFATURA_PDFTOTEXT', $pdfToText, 'User')
$env:SOLARFATURA_PDFTOTEXT = $pdfToText

Write-InstallStatus "INSTALACAO_CONCLUIDA: $pdfToText"

Write-Host 'Poppler configurado com sucesso.' -ForegroundColor Green
Write-Host "SOLARFATURA_PDFTOTEXT = $pdfToText"
Write-Host 'Feche e abra o XAMPP novamente; depois reinicie o Apache.'
