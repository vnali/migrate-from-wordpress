(function() {
    // TODO: show migration process based on selected language
    $('#language-switch-feed-status').on('change', function () {
        var language = $(this).val(); // get selected value
        if (language) {
            window.location = Craft.baseUrl + '/migrate-from-wordpress/default/index?lang=' + language; // redirect
        }
        return false;
    });
   // Add settings to migration URL
    $('#migration-grid a').on('click', function (ev) {
        if ($(this).attr("class") == 'page' || $(this).attr("class") == 'post') {
            ev.preventDefault();
            var migrateGutenbergBlocks = $('#migrateGutenbergBlocks').hasClass('on');

            // TODO: merge same block types possibility
            // var mergeSameBlockTypes = $('#mergeSameBlockTypes').hasClass('on');
            // url = $(this).attr("href") + "?migrateGutenbergBlocks=" + migrateGutenbergBlocks + "&mergeSameBlockTypes=" + mergeSameBlockTypes;
            
            url = $(this).attr("href") + "?migrateGutenbergBlocks=" + migrateGutenbergBlocks;
            window.location = url;
        }
    });
})();