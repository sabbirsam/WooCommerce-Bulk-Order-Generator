jQuery(document).ready(function($) {
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
            $('#generation-status').text('Generation stopped').removeClass().addClass('notice notice-warning');
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            return;
        }

        const remainingOrders = totalOrders - (successCount + failedCount);
        if (remainingOrders <= 0) {
            $('#generation-status').text('Generation complete!').removeClass().addClass('notice notice-success');
            $('#start-generation').prop('disabled', false);
            $('#stop-generation').prop('disabled', true);
            return;
        }

        const currentBatchSize = Math.min(batchSize, remainingOrders);
        $('#generation-status').text(`Processing batch ${currentBatch + 1}...`).removeClass().addClass('notice notice-info');

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
        $('#generation-status').text(message).removeClass().addClass('notice notice-error');
        isGenerating = false;
        $('#start-generation').prop('disabled', false);
        $('#stop-generation').prop('disabled', true);
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
        $('#generation-status').text('Starting generation...').removeClass().addClass('notice notice-info');
        $('.progress-bar').css('width', '0%');
        
        processBatch();
    });

    $('#stop-generation').on('click', function() {
        isGenerating = false;
        $(this).prop('disabled', true);
        $('#generation-status').text('Stopping generation...').removeClass().addClass('notice notice-warning');
    });

    // Initialize tooltips
    $('[data-tooltip]').tooltip();
});