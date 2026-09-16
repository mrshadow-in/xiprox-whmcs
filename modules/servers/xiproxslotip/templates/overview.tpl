{*
  xiProx Slot IP — client area service overview.
  Vars: slotId, slot (panel /slots/{id} payload), panelUrl (SSO link), error.
*}

{if $error}
    <div class="alert alert-danger">{$error|escape}</div>
{/if}

{if $slot && $slot.status == 'suspended'}
    <div class="alert alert-warning">
        <strong>Service suspended.</strong> Your provider has not cleared their bill with the datacenter.
        Please contact your provider — service resumes automatically once the account is settled.
    </div>
{/if}

{if !$slotId}
    <div class="alert alert-info">This service is not provisioned yet. It will appear here once setup completes.</div>
{elseif $slot}
    {assign var="statusClass" value="default"}
    {if $slot.status == 'active'}{assign var="statusClass" value="success"}
    {elseif $slot.status == 'suspended'}{assign var="statusClass" value="danger"}
    {elseif $slot.status == 'stopped'}{assign var="statusClass" value="warning"}
    {elseif $slot.status == 'provisioning'}{assign var="statusClass" value="info"}{/if}

    <div class="row">
        <div class="col-md-8">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">{$slot.hostname|escape}</h3></div>
                <table class="table table-striped">
                    <tbody>
                        <tr>
                            <td width="35%"><strong>Status</strong></td>
                            <td><span class="label label-{$statusClass}">{$slot.status|escape}</span></td>
                        </tr>
                        <tr>
                            <td><strong>Proxy endpoint</strong></td>
                            <td>{if $slot.ip}<code>{$slot.ip|escape}:{$slot.proxyPort|default:3128}</code>{else}—{/if}</td>
                        </tr>
                        <tr>
                            <td><strong>Proxy username</strong></td>
                            <td>{if $slot.proxyUsername}<code>{$slot.proxyUsername|escape}</code>{else}—{/if}</td>
                        </tr>
                        <tr>
                            <td><strong>Proxy password</strong></td>
                            <td>
                                <code id="xiprox-pw" data-pw="{$password|escape}">••••••••</code>
                                <a href="#" onclick="var e=document.getElementById('xiprox-pw');if(e.textContent==='••••••••'){e.textContent=e.getAttribute('data-pw');this.textContent='Hide';}else{e.textContent='••••••••';this.textContent='Show';}return false;" style="margin-left:8px;font-size:12px;">Show</a>
                            </td>
                        </tr>
                        {if $slot.plan}
                        <tr>
                            <td><strong>Plan</strong></td>
                            <td>{$slot.plan.name|default:$slot.plan.sku|escape}</td>
                        </tr>
                        {/if}
                        {if $slot.nextChargeAt}
                        <tr>
                            <td><strong>Next renewal</strong></td>
                            <td>{$slot.nextChargeAt|date_format:"%e %b %Y"}</td>
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
                    {/if}
                    <p class="text-muted" style="margin-top:8px;font-size:12px;">
                        Use the buttons above to Start, Stop, Reset the proxy user/password, or Rotate the IP.
                    </p>
                </div>
            </div>
        </div>
    </div>
{else}
    <div class="alert alert-warning">Couldn't load this slot's status from the panel right now. Please refresh in a moment.</div>
{/if}
