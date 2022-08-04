(function() {
    $(".convertTo select").change(function() {
        field = $(this).closest('tr').data('id');
        container = $(this).closest('tr').find('.container select').val();
        if (this.value == '') {
            var selectize = $('tr[data-id="' + field + '"] .selectfield select').get(0).selectize;
            selectize.clearOptions();
        } else {
            selectField(field, this.value, container);
        }
    });

    $( ".lightswitchConvert" ).on( "change", function() {
        var select = $(this).closest('tr').find('.convertTo select');
        if ($(this).hasClass('on')) {
            var optionVal = $(this).closest('tr').find('.convertTo select option:eq(1)').val();
            select.val(optionVal);
            select.trigger("change");
        } else {
            var optionVal = $(this).closest('tr').find('.convertTo select option:eq(0)').val();
            select.val(optionVal);
            select.trigger("change");
        }
    });

    // Get container fields on load
    container(0);
    
    // Load again container fields on limiting fields to layout
    $( ".lightswitchLimitFields" ).on( "change", function() {
        limit = $(this).hasClass('on');
        container(limit);
    });

    // Load again container fields on changing entry type
    $("#entrytype").change(function() {
        limit = $( ".lightswitchLimitFields" ).hasClass('on');
        container(limit);
    });
})();

// Load container fields
function container(limit) {
    var data = {
        'entryTypeId' : $('#entrytype').val(),
        'limitFieldsToLayout': limit,
        'containerTypes' : 'all',
        'item' : $('#formType').val(),
        'onlyContainer' : 0
    };

    $.ajax({
        method: "GET",
        url: Craft.getUrl("migrate-from-wordpress/default/get-container-fields" + "?=_" + new Date().getTime()),
        data: data,
        dataType: 'json',
        success: function (data) {
            var selectize = $('.container select'); 
            $.each(selectize, function(key, sel) {             
                sel.selectize.clear();
                sel.selectize.clearOptions();
             
                sel.selectize.addOption({
                    value: 'new matrix',
                    text: 'New Matrix'
                });
                
                sel.selectize.addOption({
                    value: 'new super table',
                    text: 'New Super Table'
                });
                sel.selectize.addOption({
                    value: 'new table',
                    text: 'New Table'
                });            
            });
            
            // Add returned containers option to container's select
            $.each(data, function(key, container) {             
                $.each(selectize, function(key2, sel) {             
                    sel.selectize.addOption({
                        value: container.value,
                        text: container.label,
                        $order: -1
                    });      
                });       
            });
        }
    });
}

// Show possible Craft fields
function selectField(field, convertTo, container) {

    if ($('#formType').val() == 'post' || $('#formType').val() == 'page') {
        var itemId = $('#entrytype').val();
        if (!itemId) {
            return false;
        }
    }

    var data = {
        'convertTo' : convertTo,
        'fieldContainer': container,
        'limitFieldsToLayout': $('.lightswitchLimitFields').hasClass('on'),
        'item' : $('#formType').val(),
        'itemId' : itemId
    };
    var selectize = $('tr[data-id="'+field+'"] .selectfield select').get(0).selectize;

    $.ajax({
        method: "GET",
        url: Craft.getUrl("migrate-from-wordpress/default/fields-filter" + "?=_" + new Date().getTime()),
        data: data,
        dataType: 'json',
        success: function (data) {
            selectize.clear();
            selectize.clearOptions();
            // Show available fields
            $.each(data, function(i, item) {
                selectize.addOption({
                    value: item.value,
                    text: item.label
                });
            });

            // Suggest to create new field
            if (convertTo != 'craft\\fields\\Tags' && convertTo != 'craft\\fields\\Categories' && convertTo != 'craft\\fields\\Assets') {
                if ($('#fillHandle').hasClass('on')) {
                    handle = $('tr[data-id="'+field+'"]').data('handle');
                    handle = handle.replaceAll("-", "_").replaceAll(" ", "_");
                    selectize.addOption({
                        value: handle,
                        text: handle
                    });
                    selectize.addItem(handle);
                }
            }
        }
    });
}