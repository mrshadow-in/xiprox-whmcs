{*
  xiProx Cloud — client area service overview.
  Vars: vmId, vm (panel /vms/{id} payload), panelUrl (one-time SSO link), error.
*}

{if $error}
    <div class="alert alert-danger">{$error|escape}</div>
{/if}

{if $vm && $vm.status == 'suspended'}
    <div class="alert alert-warning">
        <strong>Service suspended.</strong> Your provider has not cleared their bill with the datacenter.
        Please contact your provider — service resumes automatically once the account is settled.
    </div>
{/if}

{if !$vmId}
    <div class="alert alert-info">This service is not provisioned yet. It will appear here once setup completes.</div>
{elseif $vm}
    {assign var="statusClass" value="default"}
    {if $vm.status == 'active'}{assign var="statusClass" value="success"}
    {elseif $vm.status == 'suspended'}{assign var="statusClass" value="danger"}
    {elseif $vm.status == 'stopped'}{assign var="statusClass" value="warning"}
    {elseif $vm.status == 'provisioning'}{assign var="statusClass" value="info"}{/if}

    <div class="row">
        <div class="col-md-8">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">{$vm.hostname|escape}</h3>
                </div>
                <table class="table table-striped">
                    <tbody>
                        <tr>
                            <td width="35%"><strong>Status</strong></td>
                            <td><span class="label label-{$statusClass}">{$vm.status|escape}</span></td>
                        </tr>
                        <tr>
                            <td><strong>Primary IP</strong></td>
                            <td>{if $vm.primaryIp}<code>{$vm.primaryIp|escape}</code>{else}—{/if}</td>
                        </tr>
                        <tr>
                            <td><strong>Operating System</strong></td>
                            <td>{$vm.osTemplate|escape}</td>
                        </tr>
                        {if $vm.specs}
                        <tr>
                            <td><strong>Resources</strong></td>
                            <td>{$vm.specs.vcpu} vCPU &middot; {($vm.specs.ramMb/1024)|string_format:"%.1f"} GB RAM &middot; {$vm.specs.diskGb} GB disk</td>
                        </tr>
                        {/if}
                        {if $vm.plan}
                        <tr>
                            <td><strong>Plan</strong></td>
                            <td>{$vm.plan.name|default:$vm.plan.sku|escape}</td>
                        </tr>
                        {/if}
                        {if $vm.bandwidth && $vm.bandwidth.allowanceGb}
                        <tr>
                            <td><strong>Bandwidth (this cycle)</strong></td>
                            <td>{(($vm.bandwidth.ingressBytes + $vm.bandwidth.egressBytes)/1073741824)|string_format:"%.2f"} GB of {$vm.bandwidth.allowanceGb} GB</td>
                        </tr>
                        {/if}
                        <tr>
                            <td><strong>Username</strong></td>
                            <td><code>{$username|escape}</code></td>
                        </tr>
                        <tr>
                            <td><strong>Password</strong></td>
                            <td>
                                <code id="xiprox-pw" data-pw="{$password|escape}">••••••••</code>
                                <a href="#" onclick="var e=document.getElementById('xiprox-pw');if(e.textContent==='••••••••'){e.textContent=e.getAttribute('data-pw');this.textContent='Hide';}else{e.textContent='••••••••';this.textContent='Show';}return false;" style="margin-left:8px;font-size:12px;">Show</a>
                            </td>
                        </tr>
                        {if $vm.nextChargeAt}
                        <tr>
                            <td><strong>Next renewal</strong></td>
                            <td>{$vm.nextChargeAt|date_format:"%e %b %Y"}</td>
                        </tr>
                        {/if}
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">Manage</h3></div>
                <div class="panel-body text-center">
                    {if $panelUrl}
                        <a href="{$panelUrl|escape}" target="_blank" rel="noopener" class="btn btn-primary btn-block">
                            <i class="fas fa-external-link-alt"></i> Open Control Panel
                        </a>
                        <p class="text-muted" style="margin-top:8px;font-size:12px;">Opens your branded panel in a new tab.</p>
                    {/if}
                    <p class="text-muted" style="margin-top:8px;font-size:12px;">
                        Use the action buttons above to Start, Stop, Restart, Reinstall or Rotate IP.
                    </p>
                </div>
            </div>
        </div>
    </div>
{else}
    <div class="alert alert-warning">Couldn't load this VM's status from the panel right now. Please refresh in a moment.</div>
{/if}
