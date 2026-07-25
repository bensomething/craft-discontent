/**
 * Discontent — a VueAdminTable driven by our own toolbar.
 *
 * The tool owns the toolbar (search box, filter dropdown, view dropdown) rather
 * than injecting into Vue's rendered toolbar. Filter state is passed to the
 * server as query params on the table-data endpoint, so all filtering happens
 * server-side. `search` is disabled on the table itself — our search input
 * feeds the same endpoint param.
 *
 * Shared by the Fields and Entry Types pages; per-page specifics (which
 * controls exist, param names, edit action) come in via settings.
 */
(function ($) {
    if (typeof Craft.Discontent === 'undefined') {
        Craft.Discontent = {};
    }

    /**
     * A yes/no column: a tick when the thing is on, a faint dash when it isn't
     * — the dash is the quiet one, it's only holding the column's shape.
     *
     * Takes the icon markup because there's no iconSvg() in Twig; the pages
     * pass it through from their controller.
     */
    Craft.Discontent.flag = function (checkIcon) {
        return function (value) {
            return value
                ? '<div class="cp-icon small">' + checkIcon + '</div>'
                : '<span class="ct-faint">&mdash;</span>';
        };
    };

    Craft.Discontent.FilterTable = Garnish.Base.extend({
        settings: null,
        table: null,
        $search: null,
        $filter: null,
        $view: null,
        searchTimeout: null,

        init: function (settings) {
            this.settings = settings;

            this.$search = $(settings.searchSelector);
            this.$filter = $(settings.filterSelector);
            this.$view = $(settings.viewSelector);

            this.addListener(this.$search, 'input', 'onSearchInput');
            this.addListener(this.$search, 'keydown', 'onSearchKeydown');
            this.addListener(this.$filter, 'change', 'reload');
            this.addListener(this.$view, 'change', 'reload');

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
            var view = this.$view.val();
            if (view) {
                params[this.settings.viewParam || 'view'] = view;
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
            // Instance rows carry their edit route in the row data rather than
            // in a link, so they're handled off the table's own row event.
            config.onRowDoubleClicked = $.proxy(this, 'onInstanceDblClick');
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

        /**
         * Instance rows (the Fields page nests each field's layout instances
         * under it) don't edit a field — an instance belongs to a layout, and
         * its own settings (label, handle, instructions, conditions) are only
         * editable in that layout's field layout designer. So open the layout's
         * owner in a slideout and leave the rest to the user.
         */
        onInstanceDblClick: function (rowData) {
            var instance = rowData ? rowData.instance : null;
            if (!instance || !instance.slideout) {
                return;
            }

            // Clear the text the double-click just selected.
            if (window.getSelection) {
                window.getSelection().removeAllRanges();
            }

            var params = {};
            params[instance.slideout.param] = instance.slideout.id;
            this.openSlideout(instance.slideout.action, params);
        },

        onRowDblClick: function (ev) {
            if (!this.settings.editAction) {
                return;
            }
            // Instance rows are handled by onInstanceDblClick (their link
            // points at the owning layout, not a field).
            if ($(ev.currentTarget).find('.ct-instance').length) {
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

            this.openSlideout(this.settings.editAction, params);
        },

        openSlideout: function (action, params) {
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

            var slideout = new Craft.CpScreenSlideout(action, slideoutSettings);
            if (this.settings.readOnly) {
                slideout.$container.addClass('ct-readonly-slideout');
                slideout.$cancelBtn.text(Craft.t('app', 'Close'));
            }
            slideout.on('submit', $.proxy(this, 'reload'));

            return slideout;
        },
    });
})(jQuery);
