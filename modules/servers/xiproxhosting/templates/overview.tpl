{* xiProx Web Hosting — client area overview. All values precomputed in PHP. *}

{if $error}
    <div class="alert alert-danger">{$error|escape}</div>
{/if}

{if $status == 'suspended'}
    <div class="alert alert-warning">
        <strong>Hosting suspended.</strong>{if $suspendReason} ({$suspendReason|escape}){/if}
        Please contact your provider.
    </div>
{/if}

{if $hasAccount}
<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">{$domain|escape}</h3></div>
            <table class="table table-striped">
                <tbody>
                    <tr><td width="35%"><strong>Status</strong></td><td><span class="label label-{$statusClass}">{$status|escape}</span>{if $pending} <small class="text-muted">changes are being applied…</small>{/if}</td></tr>
                    <tr><td><strong>Username</strong></td><td><code>{$username|escape}</code></td></tr>
                    {if $packageName}<tr><td><strong>Package</strong></td><td>{$packageName|escape}</td></tr>{/if}
                    <tr><td><strong>Disk</strong></td><td>{$diskMb} MB{if $diskLimitMb} of {$diskLimitMb} MB{/if}</td></tr>
                    <tr><td><strong>Bandwidth (this month)</strong></td><td>{$bandwidthMb} MB{if $bandwidthLimitGb} of {$bandwidthLimitGb} GB{/if}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-4">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Manage</h3></div>
            <div class="panel-body text-center">
                <a href="clientarea.php?action=productdetails&amp;id={$serviceId}&amp;dosinglesignon=1" target="_blank" rel="noopener" class="btn btn-primary btn-block">Login to Hosting Panel</a>
                <p class="text-muted" style="margin-top:8px;font-size:12px;">Files, email, databases, DNS and SSL are managed in the hosting panel.</p>
            </div>
        </div>
    </div>
</div>
{/if}
