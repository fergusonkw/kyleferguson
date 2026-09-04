{{--
    Shows only the markup components the selected type actually uses.

    Markup is a percent plus a flat fee; a type uses one, both, or neither.
    Leaving an unused field on screen invites entering a number that will
    never be applied, so the fields follow the type.

    Expects: $selectName — the markup type select's `name` attribute.
--}}
<script>
(function () {
    const select = document.querySelector('select[name="{{ $selectName }}"]');
    if (!select) return;

    const USES_PERCENT = ['percent', 'hybrid'];
    const USES_FEE = ['fixed_fee', 'hybrid'];

    function syncMarkupFields () {
        const type = select.value;
        const show = (which, visible) => {
            document.querySelectorAll(`[data-markup-field="${which}"]`).forEach((el) => {
                el.classList.toggle('hidden', !visible);
                el.querySelectorAll('input').forEach((input) => {
                    input.required = visible;
                    if (!visible) input.value = '';
                });
            });
        };

        show('percent', USES_PERCENT.includes(type));
        show('fee', USES_FEE.includes(type));
    }

    select.addEventListener('change', syncMarkupFields);

    // Re-sync when the panel opens, since edit populates the type after reset.
    document.addEventListener('click', () => setTimeout(syncMarkupFields, 60));
    syncMarkupFields();

    window.syncMarkupFields = syncMarkupFields;
})();
</script>
