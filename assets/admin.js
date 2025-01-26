jQuery(document).ready(function($) {
    Dropzone.autoDiscover = false;

    // Initialize Dropzone
    if (typeof Dropzone !== 'undefined') {
        const dropzone = new Dropzone("#stwi-upload-form", {
            url: stwi_params.ajax_url,
            paramName: "file",
            maxFilesize: 10, // MB
            acceptedFiles: ".csv",
            dictDefaultMessage: stwi_params.strings.dropzone_message,

            init: function() {
                const dz = this;

                this.on("sending", function(file, xhr, formData) {
                    formData.append("action", "stwi_upload_csv");
                    formData.append("nonce", stwi_params.nonce);
                    showProgress();
                });

                this.on("success", function(file, response) {
                    if (response.success) {
                        // Pastikan import_id dikirim ke fungsi startImport
                        startImport(response.data.import_id);
                    } else {
                        showError(response.data.message);
                    }
                });

                this.on("error", function(file, message) {
                    showError(message);
                    this.removeFile(file);
                });
            }
        });
    }

    // Progress handling
    let importRunning = false;
    let currentBatch = 0;

    function startImport(importId) { // Ganti fileId menjadi importId
        importRunning = true;
        currentBatch = 0;
        resetStats();
        processBatch(importId); // Kirim importId ke fungsi processBatch
    }

    function processBatch(importId) { // Ganti fileId menjadi importId
        if (!importRunning) return;

        $.ajax({
            url: stwi_params.ajax_url, // Pastikan ini adalah ajax_url yang benar
            type: 'POST',
            data: {
                action: 'stwi_process_batch',
                nonce: stwi_params.nonce,
                import_id: importId, // Kirimkan import_id yang benar
                batch: currentBatch
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(response.data);

                    if (response.data.completed) {
                        importComplete(response.data);
                    } else {
                        currentBatch++;
                        setTimeout(function() {
                            processBatch(importId); // Ganti fileId menjadi importId
                        }, 1000);
                    }
                } else {
                    showError(response.data.message);
                    importRunning = false;
                }
            },
            error: function(xhr, status, error) {
                showError('Ajax error: ' + error);
                importRunning = false;
            }
        });
    }
    
    function updateProgress(data) {
        const percent = Math.round((data.processed / data.total) * 100);
        $('.stwi-progress-bar').css('width', percent + '%');
        $('#stwi-processed').text(data.processed);
        $('#stwi-total').text(data.total);
        $('#stwi-success').text(data.success);
        $('#stwi-errors').text(data.errors);
    }
    
    function importComplete(data) {
        importRunning = false;
        hideProgress();
        
        const message = `Import complete! Processed ${data.processed} products with ${data.errors} errors.`;
        showStatus(message, data.errors > 0 ? 'warning' : 'success');
        
        if (data.log_url) {
            showLogViewer(data.log_url);
        }
    }
    
    // UI Helpers
    function showProgress() {
        $('.stwi-progress').show();
        $('.stwi-status').hide();
    }
    
    function hideProgress() {
        $('.stwi-progress').hide();
    }
    
    function resetStats() {
        $('#stwi-processed').text('0');
        $('#stwi-success').text('0');
        $('#stwi-errors').text('0');
        $('.stwi-progress-bar').css('width', '0%');
    }
    
    function showError(message) {
        showStatus(message, 'error');
    }
    
    function showStatus(message, type = 'success') {
        const $status = $('.stwi-status');
        $status.removeClass('stwi-error stwi-warning')
               .addClass(type === 'error' ? 'stwi-error' : 
                        type === 'warning' ? 'stwi-warning' : '')
               .html(message)
               .show();
    }
    
    function showLogViewer(logUrl) {
        $.get(logUrl, function(data) {
            $('.stwi-log-viewer').html(data).show();
        });
    }
    
    // Cancel import handler
    $('#stwi-cancel-import').on('click', function(e) {
        e.preventDefault();
        importRunning = false;
        showStatus('Import cancelled by user.');
    });
});