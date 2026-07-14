/**
 * Content Tools — a VueAdminTable driven by our own toolbar.
 *
 * The tool owns the toolbar (search box + type dropdown + "Unused only"
 * lightswitch) rather than injecting into Vue's rendered toolbar. Filter
 * state is passed to the server as query params on the table-data endpoint,
 * so all filtering happens server-side. `search` is disabled on the table
 * itself — our search input feeds the same endpoint param.
 */
(function ($) {
    if (typeof Craft.ContentTools === 'undefined') {
        Craft.ContentTools = {};
    }

    Craft.ContentTools.FilterTable = Garnish.Base.extend({
        settings: null,
        table: null,
        $search: null,
        $type: null,
        $unused: null,
        searchTimeout: null,

        init: function (settings) {
            this.settings = settings;

            this.$search = $(settings.searchSelector);
            this.$type = $(settings.typeSelector);
            this.$unused = $(settings.unusedSelector);

            this.addListener(this.$search, 'input', 'onSearchInput');
            this.addListener(this.$search, 'keydown', 'onSearchKeydown');
            this.addListener(this.$type, 'change', 'reload');
            this.addListener(this.$unused, 'change', 'reload');

            this.build();
        },

        currentFilters: function () {
            var params = {};
            var search = ($.trim(this.$search.val() || ''));
            if (search) {
                params.search = search;
            }
            var type = this.$type.val();
            if (type) {
                params.type = type;
            }
            if (this.$unused.hasClass('on')) {
                params.unused = '1';
            }
            return params;
        },

        endpoint: function () {
            var qs = $.param(this.currentFilters());
            return this.settings.endpoint + (qs ? '?' + qs : '');
        },

        build: function () {
            $(this.settings.tableContainer).empty();
            this.table = new Craft.VueAdminTable({
                columns: this.settings.columns,
                container: this.settings.tableContainer,
                tableDataEndpoint: this.endpoint(),
                emptyMessage: this.settings.emptyMessage,
                search: false,
            });
        },

        reload: function () {
            this.build();
        },

        onSearchInput: function () {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout($.proxy(this, 'reload'), 300);
        },

        onSearchKeydown: function (ev) {
            if (ev.keyCode === Garnish.RETURN_KEY) {
                ev.preventDefault();
                clearTimeout(this.searchTimeout);
                this.reload();
            }
        },
    });
})(jQuery);
