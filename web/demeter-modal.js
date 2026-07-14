(function ()
{
    'use strict';

    var activeDialog = null;
    var styleInjected = false;
    var keydownHandler = null;

    function injectStyles ()
    {
        if (styleInjected)
        {
            return;
        }

        styleInjected = true;
        var style = document.createElement('style');
        style.textContent = [
            '.demeter-dialog-backdrop {',
            'position: fixed;',
            'inset: 0;',
            'z-index: 5000;',
            'display: none;',
            'align-items: center;',
            'justify-content: center;',
            'padding: 20px;',
            'background: rgba(15, 23, 42, 0.45);',
            '}',
            '.demeter-dialog-backdrop.is-open {',
            'display: flex;',
            '}',
            '.demeter-dialog {',
            'width: min(520px, 100%);',
            'background: #fff;',
            'border: 1px solid #dbe3ee;',
            'border-radius: 12px;',
            'box-shadow: 0 20px 30px rgba(15, 23, 42, 0.25);',
            'padding: 18px 20px;',
            '}',
            '.demeter-dialog-title {',
            'margin: 0 0 10px 0;',
            'font-size: 20px;',
            'color: #203a63;',
            '}',
            '.demeter-dialog-message {',
            'margin: 0 0 18px 0;',
            'font-size: 15px;',
            'line-height: 1.45;',
            'color: #334155;',
            'white-space: pre-wrap;',
            '}',
            '.demeter-dialog-actions {',
            'display: flex;',
            'justify-content: flex-end;',
            'gap: 10px;',
            'flex-wrap: wrap;',
            '}',
            '.demeter-dialog-btn {',
            'border: 1px solid #c8d3e1;',
            'background: #fff;',
            'color: #334155;',
            'border-radius: 8px;',
            'padding: 8px 14px;',
            'font: inherit;',
            'font-weight: 600;',
            'cursor: pointer;',
            '}',
            '.demeter-dialog-btn:hover {',
            'background: #f8fafc;',
            '}',
            '.demeter-dialog-btn-primary {',
            'border-color: #1f4ea6;',
            'background: #1f4ea6;',
            'color: #fff;',
            '}',
            '.demeter-dialog-btn-primary:hover {',
            'background: #1a438e;',
            '}',
            '.demeter-dialog-btn-danger {',
            'border-color: #b42318;',
            'background: #b42318;',
            'color: #fff;',
            '}',
            '.demeter-dialog-btn-danger:hover {',
            'background: #9f1f15;',
            '}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function escapeHtml (value)
    {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeOptions (options, defaults)
    {
        var normalized = defaults || {};
        if (typeof options === 'string')
        {
            normalized.message = options;
            return normalized;
        }

        if (!options || typeof options !== 'object')
        {
            return normalized;
        }

        for (var key in options)
        {
            if (Object.prototype.hasOwnProperty.call(options, key))
            {
                normalized[key] = options[key];
            }
        }

        return normalized;
    }

    function detachKeydownHandler ()
    {
        if (keydownHandler)
        {
            document.removeEventListener('keydown', keydownHandler);
            keydownHandler = null;
        }
    }

    function closeDialog (result)
    {
        if (!activeDialog)
        {
            return;
        }

        var resolve = activeDialog.resolve;
        var backdrop = activeDialog.backdrop;
        activeDialog = null;
        detachKeydownHandler();
        backdrop.classList.remove('is-open');
        backdrop.remove();
        resolve(result);
    }

    function showDialog (config)
    {
        injectStyles();

        if (activeDialog)
        {
            closeDialog(config.type === 'alert');
        }

        return new Promise(function (resolve)
        {
            var title = String(config.title || '');
            var message = String(config.message || '');
            var confirmText = String(config.confirmText || 'OK');
            var cancelText = String(config.cancelText || 'Annuleren');
            var isConfirm = config.type === 'confirm';
            var primaryClass = config.danger ? 'demeter-dialog-btn-danger' : 'demeter-dialog-btn-primary';

            var backdrop = document.createElement('div');
            backdrop.className = 'demeter-dialog-backdrop is-open';
            backdrop.setAttribute('role', 'presentation');

            var actionsHtml = [
                isConfirm
                    ? '<button type="button" class="demeter-dialog-btn" data-demeter-dialog-action="cancel">' + escapeHtml(cancelText) + '</button>'
                    : '',
                '<button type="button" class="demeter-dialog-btn ' + primaryClass + '" data-demeter-dialog-action="confirm">' + escapeHtml(confirmText) + '</button>'
            ].join('');

            backdrop.innerHTML = [
                '<div class="demeter-dialog" role="dialog" aria-modal="true" aria-labelledby="demeterDialogTitle">',
                '<h2 class="demeter-dialog-title" id="demeterDialogTitle">' + escapeHtml(title) + '</h2>',
                '<p class="demeter-dialog-message">' + escapeHtml(message) + '</p>',
                '<div class="demeter-dialog-actions">' + actionsHtml + '</div>',
                '</div>'
            ].join('');

            document.body.appendChild(backdrop);

            var confirmButton = backdrop.querySelector('[data-demeter-dialog-action="confirm"]');
            var cancelButton = backdrop.querySelector('[data-demeter-dialog-action="cancel"]');

            if (confirmButton)
            {
                confirmButton.addEventListener('click', function ()
                {
                    closeDialog(true);
                });
            }

            if (cancelButton)
            {
                cancelButton.addEventListener('click', function ()
                {
                    closeDialog(false);
                });
            }

            backdrop.addEventListener('click', function (event)
            {
                if (event.target === backdrop)
                {
                    closeDialog(false);
                }
            });

            keydownHandler = function (event)
            {
                if (event.key === 'Escape')
                {
                    event.preventDefault();
                    closeDialog(false);
                    return;
                }

                if (event.key === 'Enter')
                {
                    event.preventDefault();
                    closeDialog(true);
                }
            };
            document.addEventListener('keydown', keydownHandler);

            if (confirmButton && typeof confirmButton.focus === 'function')
            {
                confirmButton.focus();
            }

            activeDialog = {
                backdrop: backdrop,
                resolve: resolve
            };
        });
    }

    window.DemeterModal = {
        confirm: function (options)
        {
            var config = normalizeOptions(options, {
                type: 'confirm',
                title: 'Bevestigen',
                message: '',
                confirmText: 'Doorgaan',
                cancelText: 'Annuleren',
                danger: false
            });

            config.type = 'confirm';
            return showDialog(config);
        },
        alert: function (options)
        {
            var config = normalizeOptions(options, {
                type: 'alert',
                title: 'Melding',
                message: '',
                confirmText: 'OK'
            });

            config.type = 'alert';
            return showDialog(config).then(function ()
            {
                return undefined;
            });
        },
        notify: function (message, options)
        {
            var config = normalizeOptions(options, {
                type: 'alert',
                title: 'Melding',
                message: '',
                confirmText: 'OK'
            });

            if (typeof message === 'string')
            {
                config.message = message;
            }

            return window.DemeterModal.alert(config);
        }
    };
}());
