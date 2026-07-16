/**
 * Discontent — a VueAdminTable driven by our own toolbar.
 *
 * The tool owns the toolbar (search box + filter dropdown + "Unused"
 * lightswitch) rather than injecting into Vue's rendered toolbar. Filter
 * state is passed to the server as query params on the table-data endpoint,
 * so all filtering happens server-side. `search` is disabled on the table
 * itself — our search input feeds the same endpoint param.
 *
 * Shared by the Fields and Entry Types pages; per-page specifics (filter
 * param name, edit action) come in via settings.
 */
(function ($) {
    if (typeof Craft.Discontent === 'undefined') {
        Craft.Discontent = {};
    }

    Craft.Discontent.FilterTable = Garnish.Base.extend({
        settings: null,
        table: null,
        $search: null,
        $filter: null,
        $unused: null,
        searchTimeout: null,

        init: function (settings) {
            this.settings = settings;

            this.$search = $(settings.searchSelector);
            this.$filter = $(settings.filterSelector);
            this.$unused = $(settings.unusedSelector);

            this.addListener(this.$search, 'input', 'onSearchInput');
            this.addListener(this.$search, 'keydown', 'onSearchKeydown');
            this.addListener(this.$filter, 'change', 'reload');
            this.addListener(this.$unused, 'change', 'reload');

            // Delegated on the document so it survives both table rebuilds and
            // Vue replacing the mount element.
            Garnish.$doc.on(
                'dblclick',
                settings.tableContainer + ' table.data tbody tr',
                $.proxy(this, 'onRowDblClick')
            );

            this.build();
        },

        currentFilters: function () {
            var params = {};
            var search = $.trim(this.$search.val() || '');
            if (search) {
                params.search = search;
            }
            var filter = this.$filter.val();
            if (filter) {
                params[this.settings.filterParam || 'type'] = filter;
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
            var config = {
                columns: this.settings.columns,
                container: this.settings.tableContainer,
                tableDataEndpoint: this.endpoint(),
                emptyMessage: this.settings.emptyMessage,
                search: false,
            };
            if (this.settings.deleteAction) {
                config.deleteAction = this.settings.deleteAction;
                if (this.settings.deleteConfirmationMessage) {
                    config.deleteConfirmationMessage = this.settings.deleteConfirmationMessage;
                }
            }
            this.table = new Craft.VueAdminTable(config);
        },

        reload: function () {
            this.updateUrl();
            this.build();
        },

        // Keep the address bar in sync so the current view is shareable /
        // bookmarkable. replaceState (not push) so we don't spam history.
        updateUrl: function () {
            var qs = $.param(this.currentFilters());
            var url = window.location.pathname + (qs ? '?' + qs : '');
            window.history.replaceState({}, '', url);
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

        onRowDblClick: function (ev) {
            if (!this.settings.editAction) {
                return;
            }
            var regex = this.settings.editIdRegex;
            var href = $(ev.currentTarget).find('a[href]').filter(function () {
                return regex.test($(this).attr('href'));
            }).first().attr('href') || '';
            var match = href.match(regex);
            if (!match) {
                return;
            }

            // Clear the text the double-click just selected.
            if (window.getSelection) {
                window.getSelection().removeAllRanges();
            }

            var params = {};
            params[this.settings.editParam] = parseInt(match[1], 10);

            // In read-only environments the native edit action returns no save
            // action (just a read-only notice), but Craft.CpScreenSlideout still
            // renders its Cancel/Save footer buttons because it defaults to a
            // form container. Hide the (non-functional) Save button via a marker
            // class picked up in discontent.css, and repurpose the Cancel button
            // as a plain "Close" — it already dismisses the slideout. Esc /
            // shade-click work as extra dismissal paths.
            var slideoutSettings = { params: params };
            if (this.settings.readOnly) {
                slideoutSettings.closeOnEsc = true;
                slideoutSettings.closeOnShadeClick = true;
            }

            var slideout = new Craft.CpScreenSlideout(this.settings.editAction, slideoutSettings);
            if (this.settings.readOnly) {
                slideout.$container.addClass('ct-readonly-slideout');
                slideout.$cancelBtn.text(Craft.t('app', 'Close'));
            }
            slideout.on('submit', $.proxy(this, 'reload'));
        },
    });
})(jQuery);
