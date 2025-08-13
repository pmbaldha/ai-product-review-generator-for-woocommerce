jQuery(document).ready(function($) {
    
    // Auto-save functionality with debounce
    var autoSaveTimer;
    var savingFields = {};
    
    function showSaveMessage(fieldId, success = true) {
        // Remove any existing message for this field
        $('.aiprg-save-message[data-field="' + fieldId + '"]').remove();
        
        // Special handling for review sentiments (checkbox group)
        if (fieldId === 'aiprg_review_sentiments') {
            var $checkboxGroup = $('input[name="aiprg_review_sentiments[]"]').first().closest('td');
            if ($checkboxGroup.length > 0) {
                // Create and show the message
                var messageHtml = '<div class="aiprg-save-message" data-field="' + fieldId + '" style="' +
                    'display: inline-block; margin-top: 5px; padding: 5px 10px; ' +
                    'background: ' + (success ? '#d4edda' : '#f8d7da') + '; ' +
                    'color: ' + (success ? '#155724' : '#721c24') + '; ' +
                    'border: 1px solid ' + (success ? '#c3e6cb' : '#f5c6cb') + '; ' +
                    'border-radius: 3px; font-size: 13px; opacity: 0; transition: opacity 0.3s;">' +
                    (success ? aiprg_ajax.strings.setting_saved : aiprg_ajax.strings.failed_to_save) +
                    '</div>';
                
                var $message = $(messageHtml);
                
                // Find the description paragraph and place message after it
                var $description = $checkboxGroup.find('p.description');
                if ($description.length > 0) {
                    $description.after($message);
                } else {
                    $checkboxGroup.append($message);
                }
                
                // Fade in the message
                setTimeout(function() {
                    $message.css('opacity', '1');
                }, 10);
                
                // Remove the message after 3 seconds
                setTimeout(function() {
                    $message.css('opacity', '0');
                    setTimeout(function() {
                        $message.remove();
                    }, 300);
                }, 3000);
                
                return;
            }
        }
        
        // Find the field element
        var $field = $('#' + fieldId);
        if ($field.length === 0) return;
        
        // Create and show the message
        var messageHtml = '<div class="aiprg-save-message" data-field="' + fieldId + '" style="' +
            'display: inline-block; margin-top: 5px; padding: 5px 10px; ' +
            'background: ' + (success ? '#d4edda' : '#f8d7da') + '; ' +
            'color: ' + (success ? '#155724' : '#721c24') + '; ' +
            'border: 1px solid ' + (success ? '#c3e6cb' : '#f5c6cb') + '; ' +
            'border-radius: 3px; font-size: 13px; opacity: 0; transition: opacity 0.3s;">' +
            (success ? aiprg_ajax.strings.setting_saved : aiprg_ajax.strings.failed_to_save) +
            '</div>';
        
        var $message = $(messageHtml);
        
        // Position the message appropriately
        if ($field.is('select')) {
            // Check if Select2/SelectWoo is applied
            var $select2Container = $field.next('.select2-container, .selectWoo-container');
            if ($select2Container.length > 0) {
                // If Select2/SelectWoo is applied, place after the container
                $select2Container.after($message);
            } else {
                // If it's a regular select, place directly after the field
                $field.after($message);
            }
        } else if ($field.is('textarea')) {
            // For textarea fields, look for existing elements that might be after it
            var $charCounter = $field.nextAll('.aiprg-char-counter').first();
            var $placeholderButtons = $field.nextAll('.aiprg-placeholder-buttons').first();
            
            if ($placeholderButtons.length > 0) {
                // If placeholder buttons exist, place after them
                $placeholderButtons.after($message);
            } else if ($charCounter.length > 0) {
                // If character counter exists, place after it
                $charCounter.after($message);
            } else {
                // Otherwise, place directly after the textarea
                $field.after($message);
            }
        } else if ($field.is(':checkbox')) {
            $field.parent().append($message);
        } else {
            $field.after($message);
        }
        
        // Fade in the message
        setTimeout(function() {
            $message.css('opacity', '1');
        }, 10);
        
        // Remove the message after 3 seconds
        setTimeout(function() {
            $message.css('opacity', '0');
            setTimeout(function() {
                $message.remove();
            }, 300);
        }, 3000);
    }
    
    function autoSaveField(fieldId, value) {
        // Don't save if already saving this field
        if (savingFields[fieldId]) return;
        
        savingFields[fieldId] = true;
        
        // Debug logging for specific fields
        if (fieldId === 'aiprg_openai_engine' || fieldId === 'aiprg_custom_prompt' || fieldId === 'aiprg_review_sentiments') {
            console.log('Auto-saving ' + fieldId + ' field with value:', value);
        }
        
        $.post(aiprg_ajax.ajax_url, {
            action: 'aiprg_auto_save_setting',
            nonce: aiprg_ajax.auto_save_nonce || aiprg_ajax.nonce,
            field_id: fieldId,
            value: value
        }, function(response) {
            savingFields[fieldId] = false;
            
            if (response.success) {
                showSaveMessage(fieldId, true);
                // Mark settings as no longer changed since we auto-saved
                settingsChanged = false;
                
                // Debug logging for OpenAI Model field
                if (fieldId === 'aiprg_openai_engine') {
                    console.log('OpenAI Model field saved successfully');
                }
            } else {
                showSaveMessage(fieldId, false);
            }
        }).fail(function() {
            savingFields[fieldId] = false;
            showSaveMessage(fieldId, false);
        });
    }
    
    // Attach auto-save to various field types
    function attachAutoSave() {
        // Text inputs and regular selects
        $('#aiprg_openai_api_key, #aiprg_reviews_per_product, #aiprg_openai_engine, ' +
          '#aiprg_review_length_mode, #aiprg_sentiment_balance, ' +
          '#aiprg_date_range_start, #aiprg_date_range_end').on('change', function() {
            var $field = $(this);
            var fieldId = $field.attr('id');
            var value = $field.val();
            
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(function() {
                autoSaveField(fieldId, value);
            }, 500);
        });
        
        // Textareas with debounced input
        $('#aiprg_custom_prompt, #aiprg_custom_keywords').on('input', function() {
            var $field = $(this);
            var fieldId = $field.attr('id');
            var value = $field.val();
            
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(function() {
                autoSaveField(fieldId, value);
            }, 1000);
        });
        
        // Checkbox
        $('#aiprg_enable_logging').on('change', function() {
            var $field = $(this);
            var fieldId = $field.attr('id');
            var value = $field.is(':checked');
            
            autoSaveField(fieldId, value);
        });
        
        // Multi-select fields (products and categories)
        $('#aiprg_select_products, #aiprg_select_categories').on('change', function() {
            var $field = $(this);
            var fieldId = $field.attr('id');
            var value = $field.val() || [];
            
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(function() {
                autoSaveField(fieldId, value);
            }, 500);
        });
        
        // Review sentiment checkboxes
        $('input[name="aiprg_review_sentiments[]"]').on('change', function() {
            var values = [];
            $('input[name="aiprg_review_sentiments[]"]:checked').each(function() {
                values.push($(this).val());
            });
            
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(function() {
                autoSaveField('aiprg_review_sentiments', values);
            }, 500);
        });
    }
    
    // Initialize auto-save if we're on the settings tab
    if ($('.aiprg-main').length > 0 && window.location.href.indexOf('tab=settings') > -1) {
        attachAutoSave();
    }
    
    // Initialize WooCommerce product search
    if ($('.wc-product-search').length > 0) {
        // Always use our custom product search handler
        var searchAction = 'aiprg_search_products';
        console.log('Initializing product search with custom handler');
        
        function initializeProductSearch(action) {
            console.log('Setting up product search with action:', action);
            
            // Make sure selectWoo is available
            if ($.fn.selectWoo) {
                $('.wc-product-search').selectWoo({
                    ajax: {
                        url: aiprg_ajax.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            var requestData = {
                                term: params.term || '',
                                action: action,
                                security: aiprg_ajax.search_products_nonce,
                                nonce: aiprg_ajax.search_products_nonce,
                                exclude: [],
                                include: [],
                                limit: 30
                            };
                            console.log('Product search request:', requestData);
                            return requestData;
                        },
                        processResults: function(data, params) {
                            console.log('Product search response:', data);
                            console.log('Search params:', params);
                            
                            var terms = [];
                            if (data) {
                                $.each(data, function(id, text) {
                                    terms.push({
                                        id: id,
                                        text: text
                                    });
                                });
                            }
                            
                            // If no results and search term is short, show a message
                            if (terms.length === 0 && params.term && params.term.length < 3) {
                                terms.push({
                                    id: 0,
                                    text: 'Please enter at least 3 characters to search',
                                    disabled: true
                                });
                            } else if (terms.length === 0) {
                                terms.push({
                                    id: 0,
                                    text: aiprg_ajax.strings.no_products_found,
                                    disabled: true
                                });
                            }
                            
                            console.log('Processed results:', terms);
                            return {
                                results: terms
                            };
                        },
                        cache: false // Disable cache for better debugging
                    },
                    minimumInputLength: 0, // Allow empty search to show recent products
                    placeholder: aiprg_ajax.strings.search_products,
                    allowClear: true,
                    escapeMarkup: function(m) {
                        return m;
                    },
                    templateResult: function(result) {
                        if (result.disabled) {
                            return $('<span style="color: #999;">' + result.text + '</span>');
                        }
                        return result.text;
                    },
                    language: {
                        searching: function() {
                            return aiprg_ajax.strings.searching_products;
                        },
                        inputTooShort: function(args) {
                            var remainingChars = 3 - args.input.length;
                            return aiprg_ajax.strings.enter_x_characters.replace('%d', remainingChars);
                        },
                        noResults: function() {
                            return aiprg_ajax.strings.no_products_found;
                        }
                    }
                });
                console.log('Product search initialized with selectWoo');
                
                // Trigger initial search to load recent products
                $('.wc-product-search').on('select2:open', function() {
                    var $search = $('.select2-search__field');
                    if ($search.val() === '') {
                        console.log('Triggering initial product load');
                        // Trigger search with empty term to get recent products
                        var $select = $(this);
                        $select.data('select2').trigger('query', {
                            term: ''
                        });
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
                            var requestData = {
                                term: params.term || '',
                                action: action,
                                security: aiprg_ajax.search_products_nonce,
                                nonce: aiprg_ajax.search_products_nonce,
                                exclude: [],
                                include: [],
                                limit: 30
                            };
                            console.log('Product search request (Select2):', requestData);
                            return requestData;
                        },
                        processResults: function(data, params) {
                            console.log('Product search response (Select2):', data);
                            var terms = [];
                            if (data) {
                                $.each(data, function(id, text) {
                                    terms.push({
                                        id: id,
                                        text: text
                                    });
                                });
                            }
                            
                            if (terms.length === 0) {
                                terms.push({
                                    id: 0,
                                    text: aiprg_ajax.strings.no_products_found,
                                    disabled: true
                                });
                            }
                            
                            console.log('Processed results (Select2):', terms);
                            return {
                                results: terms
                            };
                        },
                        cache: false
                    },
                    minimumInputLength: 0,
                    placeholder: aiprg_ajax.strings.search_products,
                    allowClear: true
                });
                console.log('Product search initialized with Select2');
            } else {
                console.error('Neither selectWoo nor Select2 is available for product search');
            }
        }
        
        // Initialize immediately with our custom handler
        initializeProductSearch(searchAction);
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
                aiprg_ajax.strings.enter_openai_api_key
            ).show();
            return;
        }
        
        // Check if products or categories selected
        var selectedProducts = $('#aiprg_select_products').val();
        var selectedCategories = $('#aiprg_select_categories').val();
        
        if ((!selectedProducts || selectedProducts.length === 0) && (!selectedCategories || selectedCategories.length === 0)) {
            $status.removeClass('success').addClass('error').html(
                aiprg_ajax.strings.select_products_categories
            ).show();
            return;
        }
        
        // Disable button and show spinner
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.hide();
        $progress.show();
        
        // Update progress bar
        updateProgressBar(0, aiprg_ajax.strings.initializing_generation);
        
        // Prepare data
        var data = {
            action: 'aiprg_generate_reviews_scheduled',
            nonce: aiprg_ajax.nonce,
            use_scheduler: true // Disable scheduler for now since Action Scheduler is not available
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
                    detailsHtml += '<h4>' + aiprg_ajax.strings.generation_details + '</h4>';
                    detailsHtml += '<ul>';
                    
                    response.data.results.products.forEach(function(product) {
                        var successCount = product.reviews.filter(function(r) {
                            return r.status === 'success';
                        }).length;
                        
                        detailsHtml += '<li>' + product.product_name + ': ' + 
                                      successCount + aiprg_ajax.strings.reviews_generated_suffix + '</li>';
                    });
                    
                    detailsHtml += '</ul></div>';
                    $status.append(detailsHtml);
                }
                
                // Refresh page after 3 seconds to show new reviews
               /* setTimeout(function() {
                    if (confirm(aiprg_ajax.strings.reviews_generated_refresh)) {
                        location.reload();
                    }
                }, 1000);*/
                
            } else {
                $status.removeClass('success').addClass('error').html(
                    response.data.message || aiprg_ajax.error_text
                ).show();
            }
        }).fail(function(xhr, status, error) {
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
            $progress.hide();
            
            var errorMsg = aiprg_ajax.strings.error_prefix;
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg += xhr.responseJSON.data.message;
            } else if (xhr.responseText) {
                errorMsg += xhr.responseText;
            } else {
                errorMsg += error || aiprg_ajax.strings.unknown_error;
            }
            
            $status.removeClass('success').addClass('error').html(errorMsg).show();
        });
        
        // Simulate progress updates
        var progressInterval = setInterval(function() {
            getGenerationProgress();
        }, 20000);
        
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
                var text = aiprg_ajax.strings.generating_reviews_progress.replace('%1$d', response.data.current).replace('%2$d', response.data.total);
                updateProgressBar(percentage, text);
            }
        });
    }
    
    // Initialize category multi-select with AJAX search
    if ($('.wc-category-search').length > 0) {
        console.log('Initializing category search');
        
        if ($.fn.selectWoo) {
            $('.wc-category-search').selectWoo({
                ajax: {
                    url: aiprg_ajax.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        var requestData = {
                            term: params.term || '',
                            action: 'aiprg_search_categories',
                            nonce: aiprg_ajax.search_categories_nonce,
                            exclude: [],
                            include: []
                        };
                        console.log('Category search request:', requestData);
                        return requestData;
                    },
                    processResults: function(data, params) {
                        console.log('Category search response:', data);
                        var terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({
                                    id: id,
                                    text: text
                                });
                            });
                        }
                        
                        if (terms.length === 0) {
                            terms.push({
                                id: 0,
                                text: aiprg_ajax.strings.no_categories_found,
                                disabled: true
                            });
                        }
                        
                        console.log('Processed category results:', terms);
                        return {
                            results: terms
                        };
                    },
                    cache: false
                },
                minimumInputLength: 0,
                placeholder: aiprg_ajax.strings.search_categories,
                allowClear: true,
                width: '50%',
                escapeMarkup: function(m) {
                    return m;
                },
                templateResult: function(result) {
                    if (result.disabled) {
                        return $('<span style="color: #999;">' + result.text + '</span>');
                    }
                    return result.text;
                }
            });
            console.log('Category search initialized with selectWoo');
        } else if ($.fn.select2) {
            $('.wc-category-search').select2({
                ajax: {
                    url: aiprg_ajax.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        var requestData = {
                            term: params.term || '',
                            action: 'aiprg_search_categories',
                            nonce: aiprg_ajax.search_categories_nonce,
                            exclude: [],
                            include: []
                        };
                        console.log('Category search request (Select2):', requestData);
                        return requestData;
                    },
                    processResults: function(data, params) {
                        console.log('Category search response (Select2):', data);
                        var terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({
                                    id: id,
                                    text: text
                                });
                            });
                        }
                        
                        if (terms.length === 0) {
                            terms.push({
                                id: 0,
                                text: aiprg_ajax.strings.no_categories_found,
                                disabled: true
                            });
                        }
                        
                        return {
                            results: terms
                        };
                    },
                    cache: false
                },
                minimumInputLength: 0,
                placeholder: aiprg_ajax.strings.search_categories,
                allowClear: true,
                width: '50%'
            });
            console.log('Category search initialized with Select2');
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
            alert(aiprg_ajax.strings.one_sentiment_required);
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
                description = aiprg_ajax.strings.conservative;
            } else if (value <= 0.7) {
                description = aiprg_ajax.strings.balanced;
            } else {
                description = aiprg_ajax.strings.creative;
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
            alert(aiprg_ajax.strings.start_date_after_end);
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
        
        // Debug logging
        console.log('API Key validation initiated');
        
        if (!apiKey) {
            console.log('Validation failed: Empty API key');
            $result.html('<span style="color: #dc3232;">' + 'Please enter an API key first.' + '</span>');
            return;
        }
        
        // Log masked API key for debugging
        var maskedKey = apiKey.substring(0, 7) + '...' + apiKey.substring(apiKey.length - 4);
        console.log('Validating API key:', maskedKey);
        
        $button.prop('disabled', true).text(aiprg_ajax.validating_text || 'Validating...');
        $result.html('');
        
        $.post(aiprg_ajax.ajax_url, {
            action: 'aiprg_validate_api_key',
            nonce: aiprg_ajax.validate_nonce || aiprg_ajax.nonce,
            api_key: apiKey
        }, function(response) {
            console.log('API validation response received:', response);
            $button.prop('disabled', false).text('Validate API Key');
            
            if (response.success) {
                console.log('API key validation successful');
                $result.html('<span style="color: #46b450; font-weight: bold;">' + 
                    (aiprg_ajax.valid_api_key_text || '✓ Valid API Key') + '</span>');
                $('#aiprg_openai_api_key').css('border-color', '#46b450');
                
                // Show link to view logs
                $result.append('<br><a href="' + window.location.href.replace('tab=settings', 'tab=logs') + 
                              '" style="font-size: 12px; margin-top: 5px; display: inline-block;">' + aiprg_ajax.strings.view_validation_logs + '</a>');
            } else {
                console.log('API key validation failed:', response.data ? response.data.message : 'Unknown error');
                $result.html('<span style="color: #dc3232; font-weight: bold;">' + 
                    (aiprg_ajax.invalid_api_key_text || '✗ Invalid API Key') + '</span>');
                $('#aiprg_openai_api_key').css('border-color', '#dc3232');
                
                // Show link to view logs
                $result.append('<br><a href="' + window.location.href.replace('tab=settings', 'tab=logs') + 
                              '" style="font-size: 12px; margin-top: 5px; display: inline-block; color: #dc3232;">' + aiprg_ajax.strings.check_logs_details + '</a>');
            }
        }).fail(function(xhr, status, error) {
            console.error('API validation AJAX request failed:', status, error);
            console.error('Response:', xhr.responseText);
            $button.prop('disabled', false).text('Validate API Key');
            $result.html('<span style="color: #dc3232;">Failed to validate API key. Please try again.</span>');
            
            // Show link to view logs
            $result.append('<br><a href="' + window.location.href.replace('tab=settings', 'tab=logs') + 
                          '" style="font-size: 12px; margin-top: 5px; display: inline-block; color: #dc3232;">' + aiprg_ajax.strings.check_logs_details + '</a>');
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
            {text: '{product_title}', label: aiprg_ajax.strings.product_name},
            {text: '{product_description}', label: aiprg_ajax.strings.description},
            {text: '{product_price}', label: aiprg_ajax.strings.price}
        ];
        
        var $buttonContainer = $('<div class="aiprg-placeholder-buttons" style="margin-top: 5px;"></div>');
        placeholderButtons.forEach(function(btn) {
            var $button = $('<button type="button" class="button button-small" style="margin-right: 5px;" title="' + aiprg_ajax.strings.insert_placeholder.replace('%s', btn.label) + '">' + btn.text + '</button>');
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
            if (!confirm(aiprg_ajax.strings.unsaved_changes_confirm)) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Handle review deletion
    $(document).on('click', '.aiprg-delete-review', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var reviewId = $button.data('review-id');
        var nonce = $button.data('nonce');
        var $row = $button.closest('tr');
        
        // Confirm deletion
        if (!confirm(aiprg_ajax.strings.delete_review_confirm)) {
            return;
        }
        
        // Disable button and show loading state
        $button.prop('disabled', true).text(aiprg_ajax.strings.deleting);
        
        // Make AJAX request to delete the review
        $.post(aiprg_ajax.ajax_url, {
            action: 'aiprg_delete_review',
            nonce: nonce,
            review_id: reviewId
        }, function(response) {
            if (response.success) {
                // Fade out and remove the row
                $row.fadeOut(400, function() {
                    $row.remove();
                    
                    // Check if there are any reviews left
                    if ($('#aiprg-reviews-table tbody tr').length === 0) {
                        $('#aiprg-reviews-table tbody').html('<tr><td colspan="6">' + aiprg_ajax.strings.no_reviews_found + '</td></tr>');
                    }
                    
                    // Show success message
                    var $statusDiv = $('#aiprg_delete_status');
                    if ($statusDiv.length === 0) {
                        $statusDiv = $('<div id="aiprg_delete_status" class="notice notice-success is-dismissible" style="margin-top: 10px;"><p></p></div>');
                        $('#aiprg-reviews-table').before($statusDiv);
                    }
                    $statusDiv.find('p').text(response.data.message || aiprg_ajax.strings.review_deleted_success);
                    $statusDiv.fadeIn();
                    
                    // Hide success message after 3 seconds
                    setTimeout(function() {
                        $statusDiv.fadeOut();
                    }, 3000);
                });
            } else {
                // Re-enable button on error
                $button.prop('disabled', false).text(aiprg_ajax.strings.delete);
                
                // Show error message
                var errorMsg = response.data ? response.data.message : aiprg_ajax.strings.failed_delete_review;
                alert(errorMsg);
            }
        }).fail(function(xhr, status, error) {
            // Re-enable button on failure
            $button.prop('disabled', false).text(aiprg_ajax.strings.delete);
            
            // Show error message
            alert(aiprg_ajax.strings.error_deleting_review + error);
        });
    });
    
    // Handle delete all AI reviews
    $(document).on('click', '#aiprg-delete-all-reviews', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var nonce = $button.data('nonce');
        var reviewCount = parseInt($button.find('.count').text().replace(/[^0-9]/g, ''));
        
        // Confirm deletion with review count
        var confirmMessage = aiprg_ajax.strings.delete_all_reviews_confirm || 
            'Are you sure you want to delete all ' + reviewCount + ' AI-generated reviews? This action cannot be undone.';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Double confirm for safety
        var secondConfirm = aiprg_ajax.strings.delete_all_reviews_second_confirm || 
            'This will permanently delete ALL AI-generated reviews. Are you absolutely sure?';
        
        if (!confirm(secondConfirm)) {
            return;
        }
        
        // Disable button and show loading state
        var originalText = $button.html();
        $button.prop('disabled', true)
            .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + 
                  (aiprg_ajax.strings.deleting_all || 'Deleting all reviews...'));
        
        // Make AJAX request to delete all AI reviews
        $.post(aiprg_ajax.ajax_url, {
            action: 'aiprg_delete_all_reviews',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                // Show success message
                var $statusDiv = $('#aiprg_delete_status');
                if ($statusDiv.length === 0) {
                    $statusDiv = $('<div id="aiprg_delete_status" class="notice notice-success is-dismissible" style="margin-top: 10px;"><p></p></div>');
                    $('.aiprg-reviews-actions').after($statusDiv);
                }
                
                var successMessage = response.data.message || 
                    'Successfully deleted ' + response.data.deleted_count + ' AI-generated reviews.';
                $statusDiv.find('p').text(successMessage);
                $statusDiv.fadeIn();
                
                // Remove the delete all button and actions div since no reviews are left
                $('.aiprg-reviews-actions').fadeOut(400, function() {
                    $(this).remove();
                });
                
                // Clear the reviews table
                $('#aiprg-reviews-table tbody').fadeOut(400, function() {
                    $(this).html('<tr><td colspan="6">' + (aiprg_ajax.strings.no_reviews_found || 'No reviews found.') + '</td></tr>');
                    $(this).fadeIn();
                });
                
                // Update stats if visible
                if (response.data.stats) {
                    $('.aiprg-stat-value').each(function() {
                        var $stat = $(this);
                        if ($stat.text().indexOf('review') !== -1) {
                            $stat.text('0');
                        }
                    });
                }
                
                // Hide success message after 5 seconds
                setTimeout(function() {
                    $statusDiv.fadeOut();
                }, 5000);
                
            } else {
                // Re-enable button on error
                $button.prop('disabled', false).html(originalText);
                
                // Show error message
                var errorMsg = response.data ? response.data.message : 
                    (aiprg_ajax.strings.failed_delete_all_reviews || 'Failed to delete reviews. Please try again.');
                    
                var $errorDiv = $('#aiprg_delete_status');
                if ($errorDiv.length === 0) {
                    $errorDiv = $('<div id="aiprg_delete_status" class="notice notice-error is-dismissible" style="margin-top: 10px;"><p></p></div>');
                    $('.aiprg-reviews-actions').after($errorDiv);
                }
                $errorDiv.removeClass('notice-success').addClass('notice-error');
                $errorDiv.find('p').text(errorMsg);
                $errorDiv.fadeIn();
                
                // Hide error message after 5 seconds
                setTimeout(function() {
                    $errorDiv.fadeOut();
                }, 5000);
            }
        }).fail(function(xhr, status, error) {
            // Re-enable button on failure
            $button.prop('disabled', false).html(originalText);
            
            // Show error message
            var errorText = aiprg_ajax.strings.error_deleting_all_reviews || 'Error deleting reviews: ';
            
            var $errorDiv = $('#aiprg_delete_status');
            if ($errorDiv.length === 0) {
                $errorDiv = $('<div id="aiprg_delete_status" class="notice notice-error is-dismissible" style="margin-top: 10px;"><p></p></div>');
                $('.aiprg-reviews-actions').after($errorDiv);
            }
            $errorDiv.removeClass('notice-success').addClass('notice-error');
            $errorDiv.find('p').text(errorText + (error || 'Unknown error'));
            $errorDiv.fadeIn();
            
            // Hide error message after 5 seconds
            setTimeout(function() {
                $errorDiv.fadeOut();
            }, 5000);
        });
    });
});