
// Tabs ----------------------------------------
jQuery(document).ready(function($) {
    // Check localStorage for the last active tab
    let activeTab = localStorage.getItem('activeTab');

    if (activeTab) {
        // Set the saved active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');

        $(`.nav-tab[href="${activeTab}"]`).addClass('nav-tab-active');
        $(activeTab).addClass('active');
    } else {
        // Default to the first tab if no active tab is saved
        $('.nav-tab').first().addClass('nav-tab-active');
        $('.tab-content').first().addClass('active');
    }

    // Tab switching functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        // Set clicked tab as active
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        $($(this).attr('href')).addClass('active');

        // Save the active tab to localStorage
        localStorage.setItem('activeTab', $(this).attr('href'));
    });
});

// CSV importer ---------------------------------
jQuery(document).ready(function($) {
    $('.file-upload-input').on('change', function(e) {
        const file = e.target.files[0];
        const $preview = $('.file-upload-preview');
        
        if (file) {
            $preview.html(` 
                <div class="file-name-display">
                    📄 ${file.name}
                </div>
            `);
        }
    });
});

// --------------Order -------------------------------------
jQuery(document).ready(function($) {

    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }


    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    let isGenerating = false;
    let totalOrders = 0;
    let successCount = 0;
    let failedCount = 0;
    let currentBatch = 0;
    let isStopping = false;
    let startTime;

    function formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    }

    function resetAll() {
        isGenerating = false;
        isStopping = false;
        totalOrders = 0;
        successCount = 0;
        failedCount = 0;
        currentBatch = 0;
        
        // Reset all display values
        $('.progress-bar').css('width', '0%');
        $('#total-processed').text('0');
        $('#success-count').text('0');
        $('#failed-count').text('0');
        $('#processing-rate').text('0');
        $('#elapsed-time').text('0s');
        $('#time-remaining').text('--');
        $('#num_orders').val('100');
        
        // Reset buttons
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').hide();
        
        // Clear status
        $('#generation-status').hide();
    }

    function updateProgress() {
        const totalProcessed = successCount + failedCount;
        const percentage = (totalProcessed / totalOrders) * 100;
        
        $('.progress-bar').css('width', percentage + '%');
        $('#total-processed').text(totalProcessed);
        $('#success-count').text(successCount);
        $('#failed-count').text(failedCount);
        
        const elapsedTime = (Date.now() - startTime) / 1000;
        const ordersPerSecond = totalProcessed / elapsedTime;
        $('#processing-rate').text(ordersPerSecond.toFixed(2));
        
        const remainingOrders = totalOrders - totalProcessed;
        const estimatedSecondsRemaining = remainingOrders / ordersPerSecond;
        $('#time-remaining').text(formatDuration(estimatedSecondsRemaining * 1000));
        $('#elapsed-time').text(formatDuration(Date.now() - startTime));
    }

    function stopGeneration() {
        return $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'stop_order_generation',
                nonce: wcOrderGenerator.nonce
            }
        });
    }

    function processBatch() {
        if (!isGenerating || isStopping) {
            finishGeneration('Order Generation stopped', 'warning');
            showToast('Order Generation stopped', 'warning');
            return;
        }

        const totalProcessed = successCount + failedCount;
        const remainingOrders = totalOrders - totalProcessed;
        
        if (remainingOrders <= 0) {
            finishGeneration('Order Generation complete!', 'success');
            showToast('Order Generation complete!', 'success');
            return;
        }

        const currentBatchSize = Math.min($('#batch_size').val(), remainingOrders);
        console.log(currentBatchSize)
        $('#generation-status')
            .text(`Processing batch ${currentBatch + 1}...`)
            .removeClass()
            .addClass('notice notice-info')
            .show();

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_order_batch',
                nonce: wcOrderGenerator.nonce,
                batch_size: currentBatchSize,
                batch_number: currentBatch
            },
            success: function(response) {
                if (response.success) {
                    successCount += response.data.success;
                    failedCount += response.data.failed;
                    currentBatch++;
                    updateProgress();
                    
                    if (!isStopping) {
                        setTimeout(processBatch, 500);
                    } else {
                        finishGeneration('Generation stopped', 'warning');
                        showToast('Order Generation stopped', 'warning');
                        
                    }
                } else {
                    handleError('Error processing batch: ' + response.data);
                    showToast('Eror processing batch', 'error');
                }
            },
            error: function(xhr, status, error) {
                if (!isStopping) {
                    handleError('Server error occurred: ' + error);
                    showToast('Server error occurred', 'error');
                } else {
                    finishGeneration('Generation stopped', 'warning');
                    showToast('Order Generation stopped', 'warning');
                }
            }
        });
    }

    function finishGeneration(message, type) {
        $('#generation-status')
            .text(message)
            .removeClass()
            .addClass(`notice notice-${type}`)
            .show();
            
        isGenerating = false;
        isStopping = false;

        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').show();
    }

    function handleError(message) {
        const batchSize = 0;
        failedCount += batchSize;
        updateProgress();
        $('#time-remaining').text(formatDuration(failedCount));
        finishGeneration(message, 'error');
    }

    $('#order-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numOrders = parseInt($('#num_orders').val());
        if (numOrders < 1 || numOrders > 10000) {
            alert('Please enter a number between 1 and 10k');
            return;
        }
        const batch_size = parseInt($('#batch_size').val());
        if (batch_size < 5 || batch_size > 30) {
            alert('Please enter a batch number between 5 and 30');
            return;
        }

        isGenerating = true;
        isStopping = false;
        totalOrders = numOrders;
        successCount = 0;
        failedCount = 0;
        currentBatch = 0;
        startTime = Date.now();

        $('#start-generation').prop('disabled', true);
        $('#stop-generation').prop('disabled', false);
        $('#reset-generation').hide();
        $('#generation-status')
            .text('Starting generation...')
            .removeClass()
            .addClass('notice notice-info')
            .show();
        $('.progress-bar').css('width', '0%');
        
        processBatch();
    });

    $('#stop-generation').on('click', function() {
        if (!isGenerating) return;
        
        isStopping = true;
        $(this).prop('disabled', true);
        $('#generation-status')
            .text('Stopping generation...')
            .removeClass()
            .addClass('notice notice-warning')
            .show();

        stopGeneration()
            .fail(function(xhr, status, error) {
                console.error('Failed to stop generation:', error);
            });
    });

    $('#reset-generation').on('click', resetAll);
});






//------------------------------ Product ----------------------------------

jQuery(document).ready(function($) {

    // Add toast container if it doesn't exist
    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }

    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }


    // Product Generation Code
    let isGeneratingProducts = false;
    let totalProducts = 0;
    let productSuccessCount = 0;
    let productFailedCount = 0;
    let currentProductBatch = 0;
    let productStartTime;

    function resetProductGeneration() {
        isGeneratingProducts = false;
        totalProducts = 0;
        productSuccessCount = 0;
        productFailedCount = 0;
        currentProductBatch = 0;
        
        // Reset display values
        $('.product-progress-bar').css('width', '0%');
        $('#products-processed').text('0');
        $('#products-failed').text('0');
        
        // Reset buttons
        $('#start-product-generation').prop('disabled', false);
        $('#stop-product-generation').prop('disabled', true);
        $('#reset-product-generation').hide();
        
        // Clear status
        $('#product-generation-status').hide();
    }

    function updateProductProgress() {
        const totalProcessed = productSuccessCount + productFailedCount;
        const percentage = (totalProcessed / totalProducts) * 100;
        
        // Update progress bar
        $('.product-progress-bar').css('width', percentage + '%');
        
        // Update statistics
        $('#products-processed').text(productSuccessCount);
        $('#products-failed').text(productFailedCount);
    }

    function processProductBatch() {
        if (!isGeneratingProducts) {
            $('#product-generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();

            showToast('Product Generation stopped', 'warning');

            return;
        }

        const remainingProducts = totalProducts - (productSuccessCount + productFailedCount);
        if (remainingProducts <= 0) {
            $('#product-generation-status').text('Product generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-product-generation').prop('disabled', false);
            $('#stop-product-generation').prop('disabled', true);
            $('#reset-product-generation').show();
            showToast('Product Generation complete!', 'success');
            return;
        }

        const currentBatchSize = Math.min($('#product_batch_size').val(), remainingProducts);
        $('#product-generation-status').text(`Processing product batch ${currentProductBatch + 1}...`).removeClass().addClass('notice notice-info').show();

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_product_batch',
                nonce: wcOrderGenerator.products_nonce,
                batch_size: currentBatchSize,
                price_min: $('#price_min').val(),
                price_max: $('#price_max').val(),
                batch_number: currentProductBatch
            },
            
            success: function(response) {
                if (response.success) {
                    productSuccessCount += response.data.success;
                    productFailedCount += response.data.failed;
                    currentProductBatch++;
                    updateProductProgress();
                    
                    setTimeout(processProductBatch, 500);
                } else {
                    handleProductError('Error processing product batch: ' + response.data);
                }
            },
            error: function() {
                handleProductError('Server error occurred during product generation');
            }
        });
    }

    function handleProductError(message) {
        productFailedCount += parseInt($('#product_batch_size').val());
        updateProductProgress();
        $('#product-generation-status').text(message).removeClass().addClass('notice notice-error').show();
        isGeneratingProducts = false;
        $('#start-product-generation').prop('disabled', false);
        $('#stop-product-generation').prop('disabled', true);
        $('#reset-product-generation').show();
    }

    $('#product-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numProducts = parseInt($('#num_products').val());
        if (numProducts < 1 || numProducts > 10000) {
            alert('Please enter a number between 1 and 10k');
            return;
        }

        const product_batch_size = parseInt($('#product_batch_size').val());
        if (product_batch_size < 5 || product_batch_size > 30) {
            alert('Please enter a batch number between 5 and 30');
            return;
        }

        isGeneratingProducts = true;
        totalProducts = numProducts;
        productSuccessCount = 0;
        productFailedCount = 0;
        currentProductBatch = 0;
        productStartTime = Date.now();

        $('#start-product-generation').prop('disabled', true);
        $('#stop-product-generation').prop('disabled', false);
        $('#reset-product-generation').hide();
        $('#product-generation-status').text('Starting product generation...').removeClass().addClass('notice notice-info').show();
        $('.product-progress-bar').css('width', '0%');
        
        processProductBatch();
    });

    $('#stop-product-generation').on('click', function() {
        isGeneratingProducts = false;
        $(this).prop('disabled', true);
        $('#product-generation-status').text('Stopping product generation...').removeClass().addClass('notice notice-warning').show();
    });

    $('#reset-product-generation').on('click', resetProductGeneration);

    
});




//export ----------------------------------------

jQuery(document).ready(function($) {

    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }


    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }


    let exportIsGenerating = false;
    let exportTotalOrders = 0;
    let exportStartTime = 0;

    // Always set export all to true by default
    $('#export-all').prop('checked', true);

    $('#order-export-form').on('submit', function(e) {
        e.preventDefault();
        
        const batchSize = parseInt($('#export-batch-size').val());
        const exportAll = true; // Always export all orders
        const statuses = $('#export-status').val() || [];
                
        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'start_order_export',
                nonce: wcOrderGenerator.export_nonce,
                batch_size: batchSize,
                export_all: exportAll,
                statuses: statuses
            },
            success: function(response) {
                if (response.success) {
                    exportIsGenerating = true;
                    exportTotalOrders = response.data.total_orders;
                    exportStartTime = Date.now();
                    
                    // Disable export button during process
                    $('#start-order-export').prop('disabled', true);
                    
                    processExportBatch(0, response.data.export_session);
                } else {
                    showToast('Export initialization failed: ' + response.data, 'error');
                }
            }
        });
    });

    function processExportBatch(batchNumber, exportSession) {
        const batchSize = parseInt($('#export-batch-size').val());
        const statuses = $('#export-status').val() || [];
        const totalBatches = Math.ceil(exportTotalOrders / batchSize);
        
        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'export_order_batch',
                nonce: wcOrderGenerator.export_nonce,
                batch_size: batchSize,
                batch_number: batchNumber,
                total_batches: totalBatches,
                export_all: true,
                statuses: statuses,
                export_session: exportSession
            },
            success: function(response) {
                if (response.success) {
                    const processed = (batchNumber + 1) * batchSize;
                    updateExportProgress(
                        Math.min(processed, exportTotalOrders), 
                        response.data.success, 
                        response.data.failed
                    );
                    
                    if (!response.data.is_last_batch) {
                        processExportBatch(batchNumber + 1, exportSession);
                    } else {
                        exportIsGenerating = false;
                        $('#start-order-export').prop('disabled', false);
                        
                        // Trigger file download
                        window.location.href = response.data.download_url;
                    }
                } else {
                    showToast('Export batch failed!' + response.data, 'error');
                }
            }
        });
    }

    function updateExportProgress(processed, success, failed) {
        // Ensure exportTotalOrders is initialized and greater than 0
        if (!exportTotalOrders || exportTotalOrders <= 0) {
            showToast("Exporting number of order not found!", 'info')
            return;
        }
    
        const percentage = Math.min((processed / exportTotalOrders) * 100, 100); // Ensure it does not exceed 100%
        
        // Ensure progress bar width is set correctly
        $('.export-progress-bar').css({
            'width': percentage + '%',
            'background-color': 'blue',
            'height': '25px',
            'transition': 'width 0.5s ease-in-out'
        });
    
        // Update processed, success, and failed counts
        $('#export-total-processed').text(processed);
        $('#export-success-count').text(success);
        $('#export-failed-count').text(failed);
    
        // Calculate elapsed time and handle NaN
        const currentTime = Date.now();
        const elapsedTime = exportStartTime ? currentTime - exportStartTime : 0;
        $('#export-elapsed-time').text(elapsedTime > 0 ? formatDuration(elapsedTime) : '0s');
    
        // Calculate orders per second and handle NaN
        const ordersPerSecond = elapsedTime > 0 ? processed / (elapsedTime / 1000) : 0;
    
        // Calculate remaining time and handle NaN
        const remainingOrders = exportTotalOrders - processed;
        const estimatedSecondsRemaining = ordersPerSecond > 0 ? remainingOrders / ordersPerSecond : 0;
        $('#export-time-remaining').text(estimatedSecondsRemaining > 0 ? formatDuration(estimatedSecondsRemaining * 1000) : '0s');
    }

        

    function formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    }

    // Reset buttons
    $('#reset-order-export').on('click', function() {
        exportIsGenerating = false;
        $('.export-progress-bar').css('width', '0%');
        $('#export-total-processed, #export-success-count, #export-failed-count').text('0');
        $('#export-elapsed-time').text('0s');
        $('#export-time-remaining').text('--');
        $('#start-order-export').prop('disabled', false);
    });
   
});



// Import 
jQuery(document).ready(function($) {

    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }


    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }


    $('#order-import-form').on('submit', function (e) {
        e.preventDefault();

        const csvFile = $('.file-upload-input')[0].files[0];
        
        // Validate file type and extension
        if (!csvFile) {
            showToast('Please select a CSV file', 'warning');
            e.preventDefault();
            return false;
        }

        const allowedTypes = ['text/csv', 'application/vnd.ms-excel'];
        const validExtension = csvFile.name.toLowerCase().endsWith('.csv');

        if (!allowedTypes.includes(csvFile.type) || !validExtension) {
            showToast('Invalid file type. Please upload a .csv file', 'error');
            e.preventDefault();
            return false;
        }

        // Additional size check (optional)
        if (csvFile.size > 5 * 1024 * 1024) {
            showToast('File size exceeds 5MB limit', 'warning');
            e.preventDefault();
            return false;
        }
    
        var formData = new FormData(this);
        formData.append('action', 'import_orders');
        formData.append('nonce', wcOrderGenerator.import_nonce);
        formData.append('current_batch', 0);
    
        var startTime = Date.now();
        var totalOrders = 0;
        var totalImportedCount = 0;
    
        function processNextBatch(formData) {
            $.ajax({
                url: wcOrderGenerator.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    if (response.success) {
                        var endTime = Date.now();
                        var elapsedTime = Math.floor((endTime - startTime) / 1000);
    
                        totalOrders = response.data.total_orders;
                        totalImportedCount += response.data.successful;
    
                        $('#import-total-processed').text(totalImportedCount);
                        $('#import-success-count').text(totalImportedCount);
                        $('#import-failed-count').text(response.data.failed);
                        $('#import-skipped-count').text(response.data.skipped);
                        $('#import-elapsed-time').text(elapsedTime + 's');
    
                        // Update progress bar based on actual progress
                        var progressPercentage = Math.floor((totalImportedCount / totalOrders) * 100);
                        $('.import-progress-bar').css({
                            'width': progressPercentage + '%',
                            'background-color': 'blue',
                            'height': '25px',
                            'transition': 'width 0.5s ease-in-out'
                        });
    
                        // Check if import is complete
                        if (response.data.is_complete) {
                            showToast('Order Import complete!', 'success');
                            $('.import-progress-bar').css('background-color', 'green');
                            return;
                        }
    
                        // Prepare next batch
                        var nextBatchData = new FormData();
                        nextBatchData.append('action', 'import_orders');
                        nextBatchData.append('nonce', wcOrderGenerator.import_nonce);
                        nextBatchData.append('current_batch', response.data.current_batch + 1);
                        nextBatchData.append('csv_file', formData.get('csv_file'));
                        nextBatchData.append('batch_size', formData.get('batch_size'));
    
                        // Process next batch
                        processNextBatch(nextBatchData);
                    } else {
                        showToast('Order Import failed!' + response.data, 'warning');
                    }
                },
                error: function () {
                    showToast('Server error occurred!', 'error');
                }
            });
        }
    
        // Start batch processing
        processNextBatch(formData);
    });
    

     // Reset buttons
    $('#reset-order-import').on('click', function() {
        exportIsGenerating = false;
        $('.import-progress-bar').css('width', '0%');
        $('#import-total-processed, #import-success-count, #import-failed-count').text('0');
        $('#import-elapsed-time').text('0s');
        
        // Reset file upload
        $('.file-upload-input').val('');
        $('.file-upload-preview').html(`
            <div class="upload-placeholder">
                <i class="upload-icon">📤</i>
                <span class="upload-text">Drag & Drop or Click to Upload CSV</span>
            </div>
        `);
    });

});

// Delete Functionality 
jQuery(document).ready(function($) {
    const RETRY_DELAY = 3000; 
    const MAX_RETRIES = 3;

    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" style="position: fixed; top: 40px; right: 20px; z-index: 10000;"></div>');
    }


    function showToast(message, type = 'success') {
        const toast = $(`
            <div class="wc-toast ${type}">
                ${message}
            </div>
        `);
        
        $('#toast-container').append(toast);
        
        // Trigger reflow and animate in
        setTimeout(() => toast.css('opacity', '1'), 10);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.css('opacity', '0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function showConfirmDialog(type, callback) {
        const confirmDialog = $(`
            <div class="wc-confirm-dialog">
                <div class="wc-confirm-content">
                    <h3>Confirm Deletion</h3>
                    <p>Are you absolutely sure you want to delete ALL ${type}s? This action cannot be undone!</p>
                    <div class="wc-confirm-buttons">
                        <button class="button button-secondary cancel-delete">Cancel</button>
                        <button class="button button-primary confirm-delete">Confirm Delete</button>
                    </div>
                </div>
            </div>
        `).appendTo('body');

        confirmDialog.find('.cancel-delete').on('click', function() {
            confirmDialog.remove();
        });

        confirmDialog.find('.confirm-delete').on('click', function() {
            confirmDialog.remove();
            callback();
        });
    }

    function initializeDelete(type, retryCount = 0) {
        $(`#delete-${type}s-btn .poc-spinner`).show();
        $(`#delete-${type}s-btn`).prop('disabled', true);
        
        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'poc_get_counts',
                nonce: wcOrderGenerator.poc_nonce
            },
            success: function(response) {
                if (response.success) {
                    const count = type === 'product' ? response.data.product_count : response.data.order_count;
                    if (count === 0) {
                        showToast(`No ${type}s found to delete.`, 'warning');
                        // location.reload();
                        resetProgress(type);
                        return;
                    }
                    $(`#${type}-total`).text(count);
                    
                    const infoHtml = `
                        <div class="info-container" style="display: flex; justify-content: space-between; margin-top: 10px;">
                            <div style="display: flex; gap: 20px;">
                                <div class="skip-info">Skipped: <span id="${type}-skipped">0</span></div>
                                <div class="retry-info">Retry: <span id="${type}-retry-count">0</span></div>
                            </div>
                            <div class="count-progress" id="${type}-count-progress">Progress: 0/${count}</div>
                        </div>
                    `;
                    
                    $(`#${type}-progress-container .info-container`).remove();
                    $(`#${type}-progress-container`).append(infoHtml);
                    $(`#${type}-progress-container`).slideDown(300);
                    
                    showToast(`Starting ${type} deletion process...`, 'info');
                    setTimeout(() => {
                        processBatch(type, 0, count, 0, 0, 0);
                    }, 500);
                } else {
                    handleError(type, response.data || 'Error getting counts');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                if (retryCount < MAX_RETRIES) {
                    console.log(`Retrying initialization after error: ${errorThrown}`);
                    setTimeout(() => {
                        initializeDelete(type, retryCount + 1);
                    }, RETRY_DELAY);
                } else {
                    handleError(type, `Failed to initialize after ${MAX_RETRIES} attempts: ${errorThrown}`);
                }
            }
        });
    }

    function processBatch(type, offset, total, skipped, totalProcessed, retryCount) {
        console.log(`Processing batch: type=${type}, offset=${offset}, total=${total}, skipped=${skipped}, totalProcessed=${totalProcessed}, retryCount=${retryCount}`);
        
        $(`#${type}-retry-count`).text(retryCount);
        
        if (retryCount > 0) {
            skipped = parseInt($(`#${type}-skipped`).text()) + 1;
            $(`#${type}-skipped`).text(skipped);
            showToast(`Retrying batch deletion (Attempt ${retryCount} of ${MAX_RETRIES})`, 'warning');
        }

        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            timeout: 30000,
            data: {
                action: `poc_delete_${type}s_batch`,
                nonce: wcOrderGenerator.poc_nonce,
                offset: offset
            },
            success: function(response) {
                if (response.success) {
                    const batchProcessed = response.data.deleted + response.data.skipped;
                    const newTotalProcessed = totalProcessed + batchProcessed;
                    const newSkipped = skipped + (response.data.skipped || 0);
                    const percentage = Math.round((newTotalProcessed / total) * 100);
    
                    $(`#${type}-progress-bar`).css('width', percentage + '%');
                    $(`#${type}-processed`).text(newTotalProcessed);
                    $(`#${type}-skipped`).text(newSkipped);
                    $(`#${type}-count-progress`).text(`Progress: ${newTotalProcessed}/${total}`);
                    $(`#${type}-retry-count`).text('0');
    
                    if (response.data.errors && response.data.errors.length > 0) {
                        showToast(`Some ${type}s could not be deleted`, 'warning');
                        console.error(`Errors during ${type} deletion:`, response.data.errors);
                    }
    
                    if (!response.data.done) {
                        setTimeout(() => {
                            processBatch(type, offset + batchProcessed, total, newSkipped, newTotalProcessed, 0);
                        }, 1000);
                    } else {
                        setTimeout(() => {
                            verifyDeletion(type, newSkipped, newTotalProcessed);
                        }, 2000);
                    }
                } else {
                    if (retryCount < MAX_RETRIES) {
                        setTimeout(() => {
                            processBatch(type, offset, total, skipped, totalProcessed, retryCount + 1);
                        }, RETRY_DELAY);
                    } else {
                        handleError(type, response.data || `Error deleting ${type}s`);
                    }
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                if (retryCount < MAX_RETRIES) {
                    setTimeout(() => {
                        processBatch(type, offset, total, skipped, totalProcessed, retryCount + 1);
                    }, RETRY_DELAY);
                } else {
                    handleError(type, `Failed to process batch after ${MAX_RETRIES} attempts: ${errorThrown}`);
                }
            }
        });
    }

    function verifyDeletion(type, skipped, totalProcessed, retryCount = 0) {
        $.ajax({
            url: wcOrderGenerator.ajaxurl,
            type: 'POST',
            data: {
                action: 'poc_get_counts',
                nonce: wcOrderGenerator.poc_nonce
            },
            success: function(response) {
                if (response.success) {
                    const remainingCount = type === 'product' ? response.data.product_count : response.data.order_count;
                    
                    $(`#delete-${type}s-btn .poc-spinner`).hide();
                    
                    let message;
                    if (remainingCount === 0 || remainingCount === skipped) {
                        message = `Operation completed!\n${skipped} ${type}s were skipped\n${totalProcessed - skipped} ${type}s have been successfully deleted!`;
                        showToast(message, 'success');
                    } else if (remainingCount < skipped) {
                        message = `Operation completed!\n${remainingCount} ${type}s remain\n${totalProcessed - remainingCount} ${type}s have been successfully deleted!`;
                        showToast(message, 'success');
                    } else {
                        const nextRetryCount = retryCount + 1;
                        $(`#${type}-retry-count`).text(nextRetryCount);
                        showToast(`Additional ${type}s found. Continuing deletion process... (Attempt ${nextRetryCount})`, 'info');
                        setTimeout(() => {
                            initializeDelete(type);
                        }, 5000);
                        return;
                    }
                    
                    // Show detailed results in a notice
                    const noticeHtml = `
                        <div class="notice notice-success">
                            <p>${message.replace(/\n/g, '<br>')}</p>
                        </div>
                    `;
                    
                    // Insert the notice at the top of the page
                    $('.wrap h2').after(noticeHtml);
                    

                } else {
                    handleError(type, `Error verifying ${type} deletion`);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                if (retryCount < MAX_RETRIES) {
                    setTimeout(() => {
                        verifyDeletion(type, skipped, totalProcessed, retryCount + 1);
                    }, RETRY_DELAY);
                } else {
                    handleError(type, `Failed to verify deletion after ${MAX_RETRIES} attempts: ${errorThrown}`);
                }
            }
        });
    }

    function handleError(type, errorMessage) {
        console.error(errorMessage);
        $(`#delete-${type}s-btn .poc-spinner`).hide();
        $(`#delete-${type}s-btn`).prop('disabled', false);
        showToast(errorMessage, 'error');
    }

    $('#delete-products-btn').click(function() {
        showConfirmDialog('product', function() {
            initializeDelete('product');
        });
    });

    $('#delete-orders-btn').click(function() {
        showConfirmDialog('order', function() {
            initializeDelete('order');
        });
    });


    // Reset Button 
    ['product', 'order'].forEach(type => {
        $('<button>', {
            id: `reset-${type}-progress-btn`,
            class: 'button button-secondary',
            text: 'Reset',
            css: {
                'float': 'right',
                'margin-left': '10px'
            }
        }).insertAfter(`#${type}-progress-container`);
    });

    // Reset function
    function resetProgress(type) {
        // Reset progress bar
        $(`#${type}-progress-bar`).css('width', '0%');
        
        // Reset counters
        $(`#${type}-processed`).text('0');
        $(`#${type}-total`).text('0');
        $(`#${type}-skipped`).text('0');
        $(`#${type}-retry-count`).text('0');
        $(`#${type}-count-progress`).text('Progress: 0/0');
        
        // Enable delete button if it was disabled
        $(`#delete-${type}s-btn`).prop('disabled', false);
        
        // Hide spinner if it was visible
        $(`#delete-${type}s-btn .poc-spinner`).hide();

        // Show toast notification
        showToast(`${type.charAt(0).toUpperCase() + type.slice(1)} progress has been reset successfully!`, 'success');
    }

    // Bind click events to reset buttons
    $('#reset-product-progress-btn').on('click', function() {
        resetProgress('product');
    });

    $('#reset-order-progress-btn').on('click', function() {
        resetProgress('order');
    });

});