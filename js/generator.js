jQuery(document).ready(function($) {
    // Custom tooltip logic
    $('[data-tooltip]').each(function() {
        var tooltipText = $(this).attr('data-tooltip');
        // Tooltip text is already set via CSS, so we don't need extra JS
    });

    // Your existing code continues...
    let isGenerating = false;
    let totalOrders = 0;
    let successCount = 0;
    let failedCount = 0;
    let currentBatch = 0;
    const batchSize = parseInt(wcOrderGenerator.batch_size);
    let startTime;

    function formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    }

    function resetAll() {
        isGenerating = false;
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
        
        // Update progress bar
        $('.progress-bar').css('width', percentage + '%');
        
        // Update statistics
        $('#total-processed').text(totalProcessed);
        $('#success-count').text(successCount);
        $('#failed-count').text(failedCount);
        
        // Calculate and update rate
        const elapsedTime = (Date.now() - startTime) / 1000; // seconds
        const ordersPerSecond = totalProcessed / elapsedTime;
        $('#processing-rate').text(ordersPerSecond.toFixed(2));
        
        // Update estimated time remaining
        const remainingOrders = totalOrders - totalProcessed;
        const estimatedSecondsRemaining = remainingOrders / ordersPerSecond;
        $('#time-remaining').text(formatDuration(estimatedSecondsRemaining * 1000));
        
        // Update elapsed time
        $('#elapsed-time').text(formatDuration(Date.now() - startTime));
    }

    function processBatch() {
        if (!isGenerating) {
            $('#generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning').show();
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            $('#reset-generation').show();
            return;
        }

        const remainingOrders = totalOrders - (successCount + failedCount);
        if (remainingOrders <= 0) {
            $('#generation-status').text('Generation complete!').removeClass().addClass('notice notice-success').show();
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            $('#reset-generation').show();
            return;
        }

        const currentBatchSize = Math.min(batchSize, remainingOrders);
        $('#generation-status').text(`Processing batch ${currentBatch + 1}...`).removeClass().addClass('notice notice-info').show();

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
                    
                    // Reduced delay between batches for better performance
                    setTimeout(processBatch, 500);
                } else {
                    handleError('Error processing batch: ' + response.data);
                }
            },
            error: function() {
                handleError('Server error occurred');
            }
        });
    }

    function handleError(message) {
        failedCount += currentBatchSize;
        updateProgress();
        $('#generation-status').text(message).removeClass().addClass('notice notice-error').show();
        isGenerating = false;
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
        $('#reset-generation').show();
    }

    $('#order-generator-form').on('submit', function(e) {
        e.preventDefault();
        
        const numOrders = parseInt($('#num_orders').val());
        if (numOrders < 1 || numOrders > wcOrderGenerator.max_orders) {
            alert(`Please enter a number between 1 and ${wcOrderGenerator.max_orders}`);
            return;
        }

        isGenerating = true;
        totalOrders = numOrders;
        successCount = 0;
        failedCount = 0;
        currentBatch = 0;
        startTime = Date.now();

        $('#start-generation').prop('disabled', true);
        $('#stop-generation').prop('disabled', false);
        $('#reset-generation').hide();
        $('#generation-status').text('Starting generation...').removeClass().addClass('notice notice-info').show();
        $('.progress-bar').css('width', '0%');
        
        processBatch();
    });

    $('#stop-generation').on('click', function() {
        isGenerating = false;
        $(this).prop('disabled', true);
        $('#generation-status').text('Stopping generation...').removeClass().addClass('notice notice-warning').show();
    });

    $('#reset-generation').on('click', resetAll);
});