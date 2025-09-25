jQuery(document).ready(function($){
    $('#sentraiq_manual_test_btn').on('click', function(e){
        e.preventDefault();
        const url = $('#sentraiq_manual_image_url').val();
        $('#sentraiq_test_result').text('Running test...');
        $.post(sentraiq_ajax.ajax_url, {
            action: 'sentraiq_manual_test',
            security: sentraiq_ajax.nonce,
            image_url: url
        }, function(resp){
            if (resp.success) {
                $('#sentraiq_test_result').text(JSON.stringify(resp.data, null, 2));
            } else {
                $('#sentraiq_test_result').text('Error: ' + (resp.data ? JSON.stringify(resp.data) : 'unknown'));
            }
        });
    });

    function doCheckText(text) {
        $.post(sentraiq_ajax.ajax_url, {
            action: 'sentraiq_check_text',
            security: sentraiq_ajax.nonce,
            text: text
        }, function(resp){
            if (resp.success) {
                const allowed = resp.data.allowed;
                const reason = resp.data.reason || '';
                if (!allowed) {
                    if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                        try { wp.data.dispatch('core/notices').createNotice('error', 'SENTRAIQ: ' + (reason || 'Content may be disallowed'), { isDismissible: true }); }
                        catch(e) { alert('SENTRAIQ: ' + (reason || 'Content may be disallowed')); }
                    } else { alert('SENTRAIQ: ' + (reason || 'Content may be disallowed')); }
                }
            }
        });
    }

    // Classic textarea support
    const contentEl = $('#content');
    if (contentEl.length) {
        let timeout = null;
        contentEl.on('input', function(){ if (timeout) clearTimeout(timeout); timeout = setTimeout(function(){ doCheckText(contentEl.val()); }, 10000); });
    }

    // Gutenberg support
    if ( typeof wp !== 'undefined' && wp.data && wp.data.select ) {
        let gTimeout = null;
        $(document).on('input', '.block-editor-rich-text__editable', function(){ if (gTimeout) clearTimeout(gTimeout); gTimeout = setTimeout(function(){ try { const content = wp.data.select('core/editor').getEditedPostContent(); doCheckText(content); } catch(e){} }, 10000); });
    }

    // ELEMENTOR: poll for content through REST autosave or show saved status
    var isElementor = (typeof sentraiq_ajax !== 'undefined' && sentraiq_ajax.is_elementor);
    var postId = (typeof sentraiq_ajax !== 'undefined' ? sentraiq_ajax.post_id : 0);

    if ( isElementor && postId ) {
        // Poll backend for transient that indicates the post was blocked on save
        setInterval(function(){
            $.post(sentraiq_ajax.ajax_url, {
                action: 'sentraiq_check_save_status',
                security: sentraiq_ajax.nonce,
                post_id: postId
            }, function(resp){
                if ( resp && resp.success ) {
                    if ( resp.data && resp.data.blocked ) {
                        var reason = (resp.data.data && resp.data.data.reason) ? resp.data.data.reason : 'Content flagged';
                        try {
                            if ( window.elementor && window.elementor.saver ) {
                                alert('SENTRAIQ: ' + reason);
                            } else {
                                alert('SENTRAIQ: ' + reason);
                            }
                        } catch(e) {
                            alert('SENTRAIQ: ' + reason);
                        }
                    }
                }
            });
        }, 3000);
    }

});