<?php
namespace STWI;

/**
 * Handles individual product processing and image management
 */
class ProductProcessor {
    private $csv_data = array();
    private $image_errors = 0;
    private $max_image_errors = 3;
    private $timeout = 30;
    private $chunk_size = 10;
    private $processed_count = 0;
    private $error_count = 0;

    public function set_csv_data($data) {
        $this->csv_data = $data;
        stwi_error_handler('CSV data set in processor', array(
            'data_count' => count($data)
        ));
    }
    
    
    
    // Add memory management
    private function check_memory() {
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
        
        // If using more than 80% of memory limit, trigger garbage collection
        if ($memory_usage > ($memory_limit_bytes * 0.8)) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            wp_cache_flush();
        }
    }

    /**
     * Process categories with proper error handling and parent-child relationships
     */
    private function process_categories($category_string) {
        $categories = array_filter(array_map('trim', explode('>', $category_string)));
        $category_ids = array();
        $parent_id = 0;

        foreach ($categories as $category_name) {
            try {
                $term = get_term_by('name', $category_name, 'product_cat');
                
                if (!$term) {
                    $args = array(
                        'description' => '',
                        'parent' => $parent_id
                    );
                    
                    $result = wp_insert_term($category_name, 'product_cat', $args);
                    
                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                    
                    $category_ids[] = $result['term_id'];
                    $parent_id = $result['term_id'];
                } else {
                    $category_ids[] = $term->term_id;
                    $parent_id = $term->term_id;
                }
            } catch (\Exception $e) {
                stwi_error_handler('Category processing error', array(
                    'category' => $category_name,
                    'error' => $e->getMessage()
                ));
            }
        }

        return array_unique($category_ids);
    }

    /**
     * Process product variations
     */
    private function process_variations($product, $data) {
        try {
            // Validasi data variasi
            if (!isset($data['Option1 Name']) || !isset($data['Option1 Value'])) {
                stwi_error_handler('Missing variation data', $data);
                return false;
            }
    
            // Konversi ke produk variabel
            if ($product->is_type('simple')) {
                $product = new \WC_Product_Variable($product->get_id());
            }
    
            // Inisialisasi array attributes dengan validasi
            $attributes = array();
            for ($i = 1; $i <= 3; $i++) {
                $option_name = "Option{$i} Name";
                $option_value = "Option{$i} Value";
                
                if (isset($data[$option_name]) && isset($data[$option_value]) && 
                    !empty(trim($data[$option_name])) && !empty(trim($data[$option_value]))) {
                    
                    $attribute_name = wc_sanitize_taxonomy_name($data[$option_name]);
                    $attribute_values = array_map('trim', explode('|', $data[$option_value]));    
                    $attributes[$attribute_name] = array(
                        'name' => $attribute_name,
                        'value' => implode('|', $attribute_values),
                        'visible' => true,
                        'variation' => true
                    );
                }
            }
    
            // Set atribut ke produk
            $product->set_attributes($attributes);
            $product->save();
    
            // Buat variasi berdasarkan kombinasi atribut
            if (!empty($data['Variant SKU'])) {
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($product->get_id());
                $variation->set_sku($data['Variant SKU']);
                $variation->set_regular_price($data['Variant Price']);
                
                if (!empty($data['Variant Compare At Price'])) {
                    $variation->set_sale_price($data['Variant Price']);
                    $variation->set_regular_price($data['Variant Compare At Price']);
                }
    
                // Set atribut variasi
                $variation_attributes = array();
                foreach ($attributes as $attribute_name => $attribute) {
                    if (!empty($data["Option1 Value"])) {
                        $variation_attributes["attribute_" . $attribute_name] = $data["Option1 Value"];
                    }
                }
    
                $variation->set_attributes($variation_attributes);
                $variation->save();
            }
        } catch (\Exception $e) {
            stwi_error_handler('Variation processing error', array(
                'product_id' => isset($product) ? $product->get_id() : null,
                'error' => $e->getMessage()
            ));
        }
    }
    

    public function process_product($data) {
        try {
            stwi_error_handler('Starting product processing', array(
                'handle' => isset($data['Handle']) ? $data['Handle'] : '',
                'title' => isset($data['Title']) ? $data['Title'] : ''
            ));
            
            // Validate product title
            if (empty($data['Title']) || empty($data['Handle'])) {
                stwi_error_handler('Product Title or Handle is missing', $data['Title'] ," ", $data['Handle']);
                return false; // Stop processing if title is missing
            }
    
            // Validate data
            $validation = $this->validate_product_data($data);
            if ($validation !== true) {
                stwi_error_handler('Product validation failed', $validation);
                return false;
            }
            
            // Create or update product
            $product = new \WC_Product_Simple();
            if($data['Title']){
                error_log("Product Created ".$data['Title']);
            }else{
                error_log("Product Created PRODUCTS DISINIs");
            }
            
            // Set basic product data
            $product->set_name($data['Title']);
            $product->set_status('publish'); // Default status
            
            // Process images first - ini akan set status ke draft jika ada gambar invalid
            $image_result = $this->process_product_images($product, $data);
            
            // Lanjutkan dengan data produk lainnya
            if (!empty($data['Handle'])) {
                $product->set_slug($data['Handle']);
            }
            
            if (!empty($data['Body (HTML)'])) {
                $product->set_description($data['Body (HTML)']);
            }
            
            if (!empty($data['Variant SKU'])) {
                $product->set_sku($data['Variant SKU']);
            }
            
            // Set prices
            if (!empty($data['Variant Price'])) {
                $product->set_regular_price($data['Variant Price']);
                if (!empty($data['Variant Compare At Price'])) {
                    $product->set_sale_price($data['Variant Price']);
                    $product->set_regular_price($data['Variant Compare At Price']);
                }
            }
            
            // Process categories
            if (!empty($data['Type'])) {
                $category_ids = $this->process_categories($data['Type']);
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }
            
            // Handle variations if present
            if (!empty($data['Option1 Name'])) {
                $this->process_variations($product, $data);
            }
            
            // Save product
            $product_id = $product->save();
            if (!$product_id) {
                throw new \Exception('Failed to save product');
            }
            
            return true;
        } catch (\Exception $e) {
            stwi_error_handler('Product processing error', array(
                'data' => $data,
                'error' => $e->getMessage()
            ));
            return false;
        }
    }
    

    //tambahan disini
    private function process_product_images($product, $data) {
        try {
            // Validasi handle
            if (empty($data['Handle'])) {
                stwi_error_handler('Missing product handle for images', $data);
                return false;
            }
    
            $current_handle = $data['Handle'];
            $image_urls = array();
            $processed_urls = array(); // Mencegah duplikasi URL
    
            foreach ($this->csv_data as $row) {
                // Validasi data gambar
                if ($row['Handle'] === $current_handle && 
                    isset($row['Image Src']) && 
                    !empty(trim($row['Image Src'])) &&
                    !in_array($row['Image Src'], $processed_urls)) {
                    
                    $position = isset($row['Image Position']) ? (int)$row['Image Position'] : 999;
                    $image_urls[$position] = array(
                        'url' => trim($row['Image Src']),
                        'alt' => isset($row['Image Alt Text']) ? trim($row['Image Alt Text']) : ''
                    );
                    $processed_urls[] = $row['Image Src'];
                }
            }    
    
            ksort($image_urls);
            // error_log(print_r($image_urls, true));
    
            foreach ($image_urls as $image_data) {
                if ($this->check_image_accessibility($image_data['url'])) {
                    $attachment_id = $this->upload_product_image($image_data['url'], $image_data['alt']);
                    if ($attachment_id) {
                        if (!$product->get_image_id()) {
                            $product->set_image_id($attachment_id);
                        } else {
                            $gallery_ids = $product->get_gallery_image_ids();
                            $gallery_ids[] = $attachment_id;
                            $product->set_gallery_image_ids($gallery_ids);
                        }
                    } else {
                        stwi_error_handler('Failed to upload image', array(
                            'url' => $image_data['url']
                        ));
                    }
                } else {
                    stwi_error_handler('Image inaccessible', array('url' => $image_data['url']));
                }
            }
    
        } catch (\Exception $e) {
            stwi_error_handler('Error processing images', array('error' => $e->getMessage()));
        }
    }

    private function check_image_accessibility($url) {
        $response = wp_remote_head($url, array(
            'timeout' => 10,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    
    private function upload_product_image($url, $alt = '') {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Hapus query string
        $parsed_url = parse_url($url);
        $clean_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
    
        // Validasi MIME type
        $image_info = getimagesize($clean_url);
        if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png'])) {
            error_log("Invalid image MIME type: " . ($image_info['mime'] ?? 'Unknown'));
            return false;
        }
    
        $tmp = download_url($clean_url);
        if (is_wp_error($tmp)) {
            error_log("Error downloading image: " . $tmp->get_error_message());
            return false;
        }
    
        $file_array = array(
            'name' => basename($clean_url),
            'tmp_name' => $tmp
        );
    
        // Upload ke media library
        $attachment_id = media_handle_sideload($file_array, 0);
    
        // Handle upload errors
        if (is_wp_error($attachment_id)) {
            error_log("Error uploading image: " . $attachment_id->get_error_message());
            @unlink($tmp); // Delete temporary file
            return false;
        }
    
        // Set alt text for the image
        if (!empty($alt)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }
    
        return $attachment_id;
    }
    
    
    
    
    /**
     * Handle bulk processing with progress tracking
     */
    public function bulk_process($products) {
        $total = count($products);
        $chunks = array_chunk($products, $this->chunk_size);
        $progress = 0;
        
        foreach ($chunks as $chunk) {
            foreach ($chunk as $product_data) {
                $this->check_memory();
                
                if ($this->process_product($product_data)) {
                    $this->processed_count++;
                } else {
                    $this->error_count++;
                }
                
                $progress++;
                
                // Update progress in options table
                update_option('stwi_import_progress', array(
                    'total' => $total,
                    'processed' => $progress,
                    'success' => $this->processed_count,
                    'errors' => $this->error_count
                ));
            }
            
            // Small pause between chunks to prevent server overload
            sleep(1);
        }
        
        return array(
            'processed' => $this->processed_count,
            'errors' => $this->error_count
        );
    }

    /**
     * Validate product data before processing
     */
    private function validate_product_data($data) {
        $required_fields = array(
            'Handle', 
            'Title', 
            'Variant Price'
        );
        $errors = array();
        
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        return empty($errors) ? true : $errors;
    }

    /**
     * Clean up temporary files and reset counters
     */
    public function cleanup() {
        // Clean up temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/stwi-temp';
        
        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && time() - filemtime($file) > 3600) {
                    @unlink($file);
                }
            }
        }
        
        // Reset counters
        $this->processed_count = 0;
        $this->error_count = 0;
        $this->image_errors = 0;
    }
}