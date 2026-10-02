$DockerArguments = $args

$ErrorActionPreference = 'Stop'
$dockerCommand = Get-Command docker.exe -ErrorAction SilentlyContinue
$dockerExecutable = if ($dockerCommand) { $dockerCommand.Source } else {
    $candidates = @(
        (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'),
        (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe')
    )
    $candidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
}

if (-not $dockerExecutable) {
    throw 'No se encuentra Docker Desktop. Instálalo y vuelve a abrir la terminal.'
}

Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    & $dockerExecutable @DockerArguments
    $dockerExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $dockerExitCode
