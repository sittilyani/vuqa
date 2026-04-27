function saveSection(section) {
    // Show loading
    $('#loading').fadeIn();

    // Clear previous alerts
    $('.alert').hide();

    var formData = new FormData($('#' + section + 'Form')[0]);
    formData.append('section', section);

    $.ajax({
        url: 'save_section.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            $('#loading').fadeOut();
            if(response.success) {
                $('#alertSuccess').html(response.message).fadeIn();
                setTimeout(function() { $('#alertSuccess').fadeOut(); }, 3000);
                loadProgress();

                // Mark tab as completed
                var tabId = '';
                switch(section) {
                    case 'section1': tabId = 'tab1'; break;
                    case 'section2': tabId = 'tab2'; break;
                    case 'section3': tabId = 'tab3'; break;
                    case 'section4': tabId = 'tab4'; break;
                    case 'section5': tabId = 'tab5'; break;
                    case 'section6': tabId = 'tab6'; break;
                    case 'section7': tabId = 'tab7'; break;
                    case 'section8': tabId = 'tab8'; break;
                }
                if(tabId) {
                    $('.tab-btn[data-tab="' + tabId + '"] .tab-status').addClass('completed');
                }
            } else {
                $('#alertError').html(response.message).fadeIn();
                setTimeout(function() { $('#alertError').fadeOut(); }, 3000);
            }
        },
        error: function() {
            $('#loading').fadeOut();
            $('#alertError').html('Error saving data. Please try again.').fadeIn();
            setTimeout(function() { $('#alertError').fadeOut(); }, 3000);
        }
    });
}

function loadProgress() {
    $.ajax({
        url: 'get_progress.php',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if(data.success) {
                var percent = data.percentage;
                $('#completionPercent').text(percent + '%');
                $('#progressFill').css('width', percent + '%').text(percent + '%');
            }
        }
    });
}

// Auto-save periodically (every 30 seconds)
setInterval(function() {
    var activeSection = $('.form-section.active-section').attr('id');
    if(activeSection) {
        var sectionName = '';
        switch(activeSection) {
            case 'tab1': sectionName = 'section1'; break;
            case 'tab2': sectionName = 'section2'; break;
            case 'tab3': sectionName = 'section3'; break;
            case 'tab4': sectionName = 'section4'; break;
            case 'tab5': sectionName = 'section5'; break;
            case 'tab6': sectionName = 'section6'; break;
            case 'tab7': sectionName = 'section7'; break;
            case 'tab8': sectionName = 'section8'; break;
        }
        if(sectionName) {
            saveSection(sectionName);
        }
    }
}, 30000);