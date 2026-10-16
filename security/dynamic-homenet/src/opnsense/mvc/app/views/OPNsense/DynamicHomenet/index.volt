{#
 # Copyright (C) 2026 Logan Galloway
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1. Redistributions of source code must retain the above copyright notice,
 #    this list of conditions and the following disclaimer.
 #
 # 2. Redistributions in binary form must reproduce the above copyright notice,
 #    this list of conditions and the following disclaimer in the documentation
 #    and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #}

<script>
$(document).ready(function() {
    function refreshState() {
        ajaxGet("/api/dynamichomenet/settings/get", {}, function(data, status) {
            if (status !== "success" || !data.dynamichomenet) {
                return;
            }
            $("#state_ipv6").text(data.dynamichomenet.state.owned_ipv6 || "-");
            $("#state_ipv4").text(data.dynamichomenet.state.owned_ipv4 || "-");
        });
    }

    mapDataToFormUI({'frm_GeneralSettings': "/api/dynamichomenet/settings/get"}).done(function() {
        $('.selectpicker').selectpicker('refresh');
        refreshState();
    });

    $("#applyAct").SimpleActionButton({
        onPreAction: function() {
            const dfObj = $.Deferred();
            saveFormToEndpoint("/api/dynamichomenet/settings/set", 'frm_GeneralSettings', dfObj.resolve, true, dfObj.reject);
            return dfObj;
        },
        onAction: function(data, status) {
            if (status === "success" && data.status === 'ok') {
                ajaxCall("/api/dynamichomenet/service/sync", {}, function() {
                    refreshState();
                });
            }
        }
    });

    $("#forceAct").SimpleActionButton({
        onAction: function() {
            ajaxCall("/api/dynamichomenet/service/force", {}, function() {
                refreshState();
            });
        }
    });
});
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#settings">{{ lang._('Settings') }}</a></li>
    <li><a data-toggle="tab" href="#information">{{ lang._('Information') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="settings" class="tab-pane fade in active">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_GeneralSettings']) }}
        <div class="col-md-12">
            <h3>{{ lang._('Entries currently managed in Home networks') }}</h3>
            <table class="table table-condensed">
                <tr><td style="width:15%">{{ lang._('IPv6 prefix') }}</td><td id="state_ipv6">-</td></tr>
                <tr><td>{{ lang._('IPv4 address') }}</td><td id="state_ipv4">-</td></tr>
            </table>
            <button class="btn btn-primary" id="applyAct"
                    data-endpoint="/api/dynamichomenet/service/sync"
                    data-label="{{ lang._('Apply') }}"
                    type="button">
                {{ lang._('Apply') }}
            </button>
            <button class="btn btn-default" id="forceAct"
                    data-endpoint="/api/dynamichomenet/service/force"
                    data-label="{{ lang._('Sync now') }}"
                    type="button">
                {{ lang._('Sync now') }}
            </button>
            <p class="text-muted">
                <small>
                    {{ lang._('Apply saves the settings and updates the Home networks list if the prefix has changed.') }}
                    {{ lang._('Sync now updates it even when nothing seems to have changed, for example to restore an entry that was removed by hand.') }}
                </small>
            </p>
            <br/>
        </div>
    </div>

    <div id="information" class="tab-pane fade in">
        <div class="col-md-12">
            <h3>{{ lang._('What this does') }}</h3>
            <p>{{ lang._('Intrusion Detection (Suricata) uses the "Home networks" list to decide which addresses are yours. With IPv6 your provider can change the prefix delegated to this firewall at any time, for example after a reconnect. When that happens the list no longer covers your LAN and your own devices are treated as outside traffic, so rules that depend on the Home networks silently stop matching.') }}</p>
            <p>{{ lang._('This plugin adds the delegated IPv6 prefix of the selected interface to the Home networks list and replaces it when the prefix changes. It can also do the same for the current IPv4 address of that interface. Each of the two can be switched on or off.') }}</p>

            <h3>{{ lang._('Recommended setup for the best security') }}</h3>
            <p>{{ lang._('The default Home networks list contains the whole private IPv4 ranges 192.168.0.0/16, 10.0.0.0/8 and 172.16.0.0/12. They are far broader than most networks. For example 10.0.0.0/8 also covers addresses on your provider side of the connection, so everything there is treated as your own network and skipped by the rules that look for attacks coming from outside.') }}</p>
            <p>{{ lang._('We suggest removing all the default IPv4 entries from the Home networks list and adding only the subnets you really use by hand. Let this plugin cover the rest: the IPv6 prefix and, with the IPv4 option, the address of the firewall itself, which usually changes and cannot be entered by hand.') }}</p>

            <h3>{{ lang._('What it will not do') }}</h3>
            <ul>
                <li>{{ lang._('It does nothing until it is enabled.') }}</li>
                <li>{{ lang._('It never restarts Suricata, the rules are reloaded.') }}</li>
                <li>{{ lang._('It never changes or removes entries you added yourself, only the ones it added.') }}</li>
                <li>{{ lang._('It does nothing when the prefix has not changed, so ordinary interface reconfigures cause no reload.') }}</li>
            </ul>

            <h3>{{ lang._('Good to know') }}</h3>
            <ul>
                <li>{{ lang._('Intrusion Detection has to be enabled. If Suricata is not running, the list is still updated and is used when it starts.') }}</li>
                <li>{{ lang._('While the interface has no delegated prefix or address (for example during a reconnect) nothing is changed. The previous entry stays until a new one arrives.') }}</li>
                <li>{{ lang._('Turning the plugin off does not remove the entry it added. Remove it from the Home networks list if you no longer want it.') }}</li>
                <li>{{ lang._('Activity is logged under the name "dynamichomenet" in System > Log Files > General.') }}</li>
            </ul>
            <br/>
        </div>
    </div>
</div>
