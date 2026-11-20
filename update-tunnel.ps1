param(
    [Parameter(Mandatory=$true)]
    [string]$TunnelUrl,  # e.g. https://xxx.trycloudflare.com

    [string]$Issuer = "http://localhost:9080",
    [string]$ClientId = "h5p-hub-client",
    [string]$DeploymentId = "deployment-9080"
)

$TunnelUrl = $TunnelUrl.TrimEnd('/')

# Update .env and compose.yaml
(Get-Content .env) -replace 'APP_URL=.*', "APP_URL=$TunnelUrl" | Set-Content .env
(Get-Content compose.yaml) -replace '    APP_URL:.*', "    APP_URL: $TunnelUrl" | Set-Content compose.yaml

# Write registration JSON
$reg = @{
    tenant_slug      = "platform-9080"
    tenant_name      = "Platform localhost:9080"
    name             = "Platform localhost:9080"
    issuer           = $Issuer
    client_id        = $ClientId
    authorization_url = "$Issuer/lti/authorize"
    jwks_url         = "$Issuer/lti/jwks"
    deployment_id    = $DeploymentId
    allowed_origins  = @($Issuer, $TunnelUrl)
    tenant_active    = $true
    platform_active  = $true
    deployment_active = $true
} | ConvertTo-Json
$reg | Set-Content lti-registration.json

# Restart app and re-register
docker compose up -d app worker scheduler
Start-Sleep -Seconds 4
docker compose exec -T app php artisan lti:register /var/www/html/lti-registration.json
docker compose exec app php artisan route:clear
Remove-Item lti-registration.json

Write-Host ""
Write-Host "=== URLs cap nhat cho platform ===" -ForegroundColor Green
Write-Host "Tool Login URL  : $TunnelUrl/lti/login"
Write-Host "Tool Redirect   : $TunnelUrl/lti/launch"
Write-Host "Target Link URI : $TunnelUrl/lti/launch"
Write-Host "Public JWKS     : $TunnelUrl/lti/jwks"
