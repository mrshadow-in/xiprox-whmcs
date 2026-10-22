{* xiProx Cloud — client area overview. All values are precomputed in PHP. *}

{if $error}
    <div class="alert alert-danger">{$error|escape}</div>
{/if}

{if $status == 'suspended'}
    <div class="alert alert-warning">
        <strong>Service suspended.</strong> Your provider has not cleared their bill with the datacenter.
        Please contact your provider — service resumes automatically once the account is settled.
    </div>
{/if}

{if $hasVm}
<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">{$hostname|escape}</h3></div>
            <table class="table table-striped">
                <tbody>
                    <tr><td width="35%"><strong>Status</strong></td><td><span class="label label-{$statusClass}">{$status|escape}</span></td></tr>
                    <tr><td><strong>Primary IP</strong></td><td>{if $primaryIp}<code>{$primaryIp|escape}</code>{else}—{/if}</td></tr>
                    <tr><td><strong>Operating System</strong></td><td>{$os|escape}</td></tr>
                    {if $specsText}<tr><td><strong>Resources</strong></td><td>{$specsText|escape}</td></tr>{/if}
                    {if $planName}<tr><td><strong>Plan</strong></td><td>{$planName|escape}</td></tr>{/if}
                    <tr><td><strong>Username</strong></td><td><code>{$username|escape}</code></td></tr>
                    <tr>
                        <td><strong>Password</strong></td>
                        <td>
                            <code id="xiprox-pw" data-pw="{$password|escape}">••••••••</code>
                            {literal}<a href="#" onclick="var e=document.getElementById('xiprox-pw');if(e.textContent==='••••••••'){e.textContent=e.getAttribute('data-pw');this.textContent='Hide';}else{e.textContent='••••••••';this.textContent='Show';}return false;" style="margin-left:8px;font-size:12px;">Show</a>{/literal}
                        </td>
                    </tr>
                    {if $bandwidthText}<tr><td><strong>Bandwidth (this cycle)</strong></td><td>{$bandwidthText|escape}</td></tr>{/if}
                    {if $nextRenewal}<tr><td><strong>Next renewal</strong></td><td>{$nextRenewal|escape}</td></tr>{/if}
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-4">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Manage</h3></div>
            <div class="panel-body text-center">
                {if $panelUrl}
                    <a href="{$panelUrl|escape}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Open Control Panel</a>
                    <p class="text-muted" style="margin-top:8px;font-size:12px;">Opens your branded panel in a new tab.</p>
                {elseif $ssoError}
                    <p class="text-muted" style="font-size:12px;">Panel login unavailable: {$ssoError|escape}</p>
                {/if}
                <p class="text-muted" style="margin-top:8px;font-size:12px;">Use the buttons in the sidebar to Start, Stop, Restart, Force Stop or Reinstall.</p>
            </div>
        </div>
    </div>
</div>
{elseif !$error}
    <div class="alert alert-info">Loading your service…</div>
{/if}
