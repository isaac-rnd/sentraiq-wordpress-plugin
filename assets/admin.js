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
    const contentEl = $('#content');
    if (contentEl.length) {
        let timeout=null;
        contentEl.on('input', function(){
            if(timeout) clearTimeout(timeout);
            timeout=setTimeout(doCheck,10000);
        });
        function doCheck(){
            const text=contentEl.val();
            $.post(sentraiq_ajax.ajax_url, {
                action: 'sentraiq_check_text',
                security: sentraiq_ajax.nonce,
                text: text
            }, function(resp){
                if(resp.success && resp.data && resp.data.allowed===false){
                    alert('SENTRAIQ: Your post contains disallowed content.');
                }
            });
        }
    }
});