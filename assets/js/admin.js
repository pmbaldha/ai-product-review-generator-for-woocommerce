jQuery(document).ready(function($) {
    
    // Initialize WooCommerce product search
    if ($('.wc-product-search').length > 0) {
        // Make sure selectWoo is available
        if ($.fn.selectWoo) {
            $('.wc-product-search').selectWoo({
                ajax: {
                    url: aiprg_ajax.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: 'woocommerce_json_search_products_and_variations',
                            security: aiprg_ajax.search_products_nonce,
                            exclude: [],
                            include: [],
                            limit: 30
                        };
                    },
                    processResults: function(data) {
                        var terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({
                                    id: id,
                                    text: text
                                });
                            });
                        }
                        return {
                            results: terms
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3,
                escapeMarkup: function(m) {
                    return m;
                },
                templateResult: function(result) {
                    return result.text;
                }
            });
        } else if ($.fn.select2) {
            // Fallback to select2 if selectWoo is not available
            $('.wc-product-search').select2({
                ajax: {
                    url: aiprg_ajax.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: 'woocommerce_json_search_products_and_variations',
                            security: aiprg_ajax.search_products_nonce
                        };
                    },
                    processResults: function(data) {
                        var terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({
                                    id: id,
                                    text: text
                                });
                            });
                        }
                        return {
                            results: terms
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3
            });
        }
    }
    
    // Handle review generation
    $('#aiprg_generate_reviews').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $status = $('#aiprg_generation_status');
        var $progress = $('#aiprg_generation_progress');
        
        // Validate API key
        var apiKey = $('#aiprg_openai_api_key').val();
        if (!apiKey) {
            $status.removeClass('success').addClass('error').html(
                'Please enter your OpenAI API key before generating reviews.'
            ).show();
            return;
        }
        
        // Check if products or categories selected
        var selectedProducts = $('#aiprg_select_products').val();
        var selectedCategories = $('#aiprg_select_categories').val();
        
        if ((!selectedProducts || selectedProducts.length === 0) && (!selectedCategories || selectedCategories.length === 0)) {
            $status.removeClass('success').addClass('error').html(
                'Please select products or categories before generating reviews.'
            ).show();
            return;
        }
        
        // Disable button and show spinner
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.hide();
        $progress.show();
        
        // Update progress bar
        updateProgressBar(0, 'Initializing review generation...');
        
        // Prepare data
        var data = {
            action: 'aiprg_generate_reviews_scheduled',
            nonce: aiprg_ajax.nonce,
            product_ids: selectedProducts,
            use_scheduler: false // Disable scheduler for now since Action Scheduler is not available
        };
        
        // Make AJAX request
        $.post(aiprg_ajax.ajax_url, data, function(response) {
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
            $progress.hide();
            
            if (response.success) {
                $status.removeClass('error').addClass('success').html(response.data.message).show();
                
                // Show detailed results if available
                if (response.data.results && response.data.results.products) {
                    var detailsHtml = '<div class="aiprg-results-details">';
                    detailsHtml += '<h4>Generation Details:</h4>';
                    detailsHtml += '<ul>';
                    
                    response.data.results.products.forEach(function(product) {
                        var successCount = product.reviews.filter(function(r) {
                            return r.status === 'success';
                        }).length;
                        
                        detailsHtml += '<li>' + product.product_name + ': ' + 
                                      successCount + ' reviews generated</li>';
                    });
                    
                    detailsHtml += '</ul></div>';
                    $status.append(detailsHtml);
                }
                
                // Refresh page after 3 seconds to show new reviews
                setTimeout(function() {
                    if (confirm('Reviews generated successfully! Refresh page to see the new reviews?')) {
                        location.reload();
                    }
                }, 1000);
                
            } else {
                $status.removeClass('success').addClass('error').html(
                    response.data.message || aiprg_ajax.error_text
                ).show();
            }
        }).fail(function(xhr, status, error) {
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
            $progress.hide();
            
            var errorMsg = 'Error: ';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg += xhr.responseJSON.data.message;
            } else if (xhr.responseText) {
                errorMsg += xhr.responseText;
            } else {
                errorMsg += error || 'Unknown error occurred';
            }
            
            $status.removeClass('success').addClass('error').html(errorMsg).show();
        });
        
        // Simulate progress updates
        var progressInterval = setInterval(function() {
            getGenerationProgress();
        }, 1000);
        
        // Clear interval after 60 seconds (timeout)
        setTimeout(function() {
            clearInterval(progressInterval);
        }, 60000);
    });
    
    function updateProgressBar(percentage, text) {
        var $progressBar = $('.progress-bar-fill');
        var $progressText = $('.progress-text');
        
        $progressBar.css('width', percentage + '%');
        if (percentage > 0) {
            $progressBar.text(percentage + '%');
        }
        $progressText.text(text);
    }
    
    function getGenerationProgress() {
        $.post(aiprg_ajax.ajax_url, {
            action: 'aiprg_get_generation_progress',
            nonce: aiprg_ajax.nonce
        }, function(response) {
            if (response.success && response.data.in_progress) {
                var percentage = response.data.percentage || 0;
                var text = 'Generating reviews... (' + response.data.current + '/' + response.data.total + ')';
                updateProgressBar(percentage, text);
            }
        });
    }
    
    // Initialize category multi-select with Select2/SelectWoo
    if ($('.wc-category-search').length > 0) {
        if ($.fn.selectWoo) {
            $('.wc-category-search').selectWoo({
                placeholder: 'Select categories...',
                allowClear: true,
                width: '50%'
            });
        } else if ($.fn.select2) {
            $('.wc-category-search').select2({
                placeholder: 'Select categories...',
                allowClear: true,
                width: '50%'
            });
        }
    }
    
    // Category and products selection logic - allow both to be selected
    $('#aiprg_select_categories').on('change', function() {
        // No longer hide products when categories are selected
        // Users can select both products and categories
    });
    
    $('#aiprg_select_products').on('change', function() {
        // No longer clear categories when products are selected
        // Users can select both products and categories
    });
    
    // Review sentiment checkboxes validation
    $('input[name="aiprg_review_sentiments[]"]').on('change', function() {
        var checkedCount = $('input[name="aiprg_review_sentiments[]"]:checked').length;
        if (checkedCount === 0) {
            $(this).prop('checked', true);
            alert('At least one sentiment must be selected.');
        }
    });
    
    // Temperature slider
    var $temperatureInput = $('#aiprg_temperature');
    if ($temperatureInput.length > 0) {
        var $temperatureDisplay = $('<span class="temperature-display" style="margin-left: 10px; font-weight: bold;"></span>');
        $temperatureInput.after($temperatureDisplay);
        
        function updateTemperatureDisplay() {
            var value = parseFloat($temperatureInput.val());
            var description = '';
            
            if (value <= 0.3) {
                description = 'Conservative';
            } else if (value <= 0.7) {
                description = 'Balanced';
            } else {
                description = 'Creative';
            }
            
            $temperatureDisplay.text(value.toFixed(1) + ' (' + description + ')');
        }
        
        $temperatureInput.on('input change', updateTemperatureDisplay);
        updateTemperatureDisplay();
    }
    
    // Date range validation
    $('#aiprg_date_range_start, #aiprg_date_range_end').on('change', function() {
        var startDate = new Date($('#aiprg_date_range_start').val());
        var endDate = new Date($('#aiprg_date_range_end').val());
        
        if (startDate > endDate) {
            alert('Start date cannot be after end date.');
            if ($(this).attr('id') === 'aiprg_date_range_start') {
                $(this).val($('#aiprg_date_range_end').val());
            } else {
                $(this).val($('#aiprg_date_range_start').val());
            }
        }
    });
    
    // API Key validation button (using the button added in PHP)
    $('#aiprg-validate-api-key').on('click', function() {
        var apiKey = $('#aiprg_openai_api_key').val();
        var $button = $(this);
        var $result = $('#aiprg-validation-result');
        
        if (!apiKey) {
            $result.html('<span style="color: #dc3232;">' + 'Please enter an API key first.' + '</span>');
            return;
        }
        
        $button.prop('disabled', true).text(aiprg_ajax.validating_text || 'Validating...');
        $result.html('');
        
        $.post(aiprg_ajax.ajax_url, {
            action: 'aiprg_validate_api_key',
            nonce: aiprg_ajax.validate_nonce || aiprg_ajax.nonce,
            api_key: apiKey
        }, function(response) {
            $button.prop('disabled', false).text('Validate API Key');
            
            if (response.success) {
                $result.html('<span style="color: #46b450; font-weight: bold;">' + 
                    (aiprg_ajax.valid_api_key_text || '✓ Valid API Key') + '</span>');
                $('#aiprg_openai_api_key').css('border-color', '#46b450');
            } else {
                $result.html('<span style="color: #dc3232; font-weight: bold;">' + 
                    (aiprg_ajax.invalid_api_key_text || '✗ Invalid API Key') + '</span>');
                $('#aiprg_openai_api_key').css('border-color', '#dc3232');
            }
        }).fail(function() {
            $button.prop('disabled', false).text('Validate API Key');
            $result.html('<span style="color: #dc3232;">Failed to validate API key. Please try again.</span>');
        });
    });
    
    // Custom prompt character counter
    var $customPrompt = $('#aiprg_custom_prompt');
    if ($customPrompt.length > 0) {
        function updateCharCount() {
            var charCount = $customPrompt.val().length;
            var maxLength = $customPrompt.attr('maxlength') || 500;
            $('#aiprg-prompt-char-count').text(charCount);
            
            // Change color based on character count
            if (charCount > maxLength * 0.9) {
                $('#aiprg-prompt-char-count').css('color', '#d63638');
            } else if (charCount > maxLength * 0.7) {
                $('#aiprg-prompt-char-count').css('color', '#dba617');
            } else {
                $('#aiprg-prompt-char-count').css('color', '#666');
            }
        }
        
        $customPrompt.on('input keyup', updateCharCount);
        updateCharCount(); // Initial count
        
        // Add placeholder helper buttons
        var placeholderButtons = [
            {text: '{product_title}', label: 'Product Name'},
            {text: '{product_description}', label: 'Description'},
            {text: '{product_price}', label: 'Price'}
        ];
        
        var $buttonContainer = $('<div class="aiprg-placeholder-buttons" style="margin-top: 5px;"></div>');
        placeholderButtons.forEach(function(btn) {
            var $button = $('<button type="button" class="button button-small" style="margin-right: 5px;" title="Insert ' + btn.label + '">' + btn.text + '</button>');
            $button.on('click', function(e) {
                e.preventDefault();
                var cursorPos = $customPrompt[0].selectionStart;
                var textBefore = $customPrompt.val().substring(0, cursorPos);
                var textAfter = $customPrompt.val().substring(cursorPos);
                $customPrompt.val(textBefore + btn.text + textAfter);
                $customPrompt[0].setSelectionRange(cursorPos + btn.text.length, cursorPos + btn.text.length);
                $customPrompt.focus();
                updateCharCount();
            });
            $buttonContainer.append($button);
        });
        $customPrompt.after($buttonContainer);
    }
    
    // Custom keywords enhancement
    var $customKeywords = $('#aiprg_custom_keywords');
    if ($customKeywords.length > 0) {
        // Add example keywords as clickable tags
        $('.aiprg-tag-example').css('cursor', 'pointer').on('click', function() {
            var keyword = $(this).text();
            var currentVal = $customKeywords.val();
            if (currentVal) {
                if (currentVal.indexOf(keyword) === -1) {
                    $customKeywords.val(currentVal + ', ' + keyword);
                }
            } else {
                $customKeywords.val(keyword);
            }
            $customKeywords.focus();
        });
    }
    
    // Help tooltips
    var helpTexts = {
        'aiprg_sentiment_balance': 'Controls how sentiments are distributed across generated reviews.',
        'aiprg_review_length_mode': 'Choose consistent length or mix different lengths for more natural variety.'
    };
    
    $.each(helpTexts, function(fieldId, helpText) {
        var $field = $('#' + fieldId);
        if ($field.length > 0) {
            var $helpIcon = $('<span class="dashicons dashicons-editor-help" style="cursor: help; color: #666; margin-left: 5px;" title="' + helpText + '"></span>');
            $field.closest('td').find('label').first().append($helpIcon);
        }
    });
    
    // Save settings reminder
    var settingsChanged = false;
    $('.form-table input, .form-table select, .form-table textarea').on('change', function() {
        settingsChanged = true;
    });
    
    $('#aiprg_generate_reviews').on('click', function(e) {
        if (settingsChanged) {
            if (!confirm('You have unsaved changes. Please save your settings first. Continue anyway?')) {
                e.preventDefault();
                return false;
            }
        }
    });
});