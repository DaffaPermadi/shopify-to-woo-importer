jQuery(document).ready(function($) {
    Dropzone.autoDiscover = false;

    const dropzone = new Dropzone("#stwi-upload-form", {
        url: stwi_params.ajax_url,
        paramName: "file",
        maxFilesize: 14, // Dalam MB
        acceptedFiles: ".csv",
        autoProcessQueue: true, // Ubah ke true
        dictDefaultMessage: stwi_params.strings.dropzone_message,
        init: function() {
            this.on("sending", function(file, xhr, formData) {
                formData.append("action", "stwi_upload_csv");
                formData.append("nonce", stwi_params.nonce);
            });

            this.on("success", function(file, response) {
                if (response.success) {
                    addFileToList(response.data.import_id, file.name);
                    $(".stwi-files-section").show();
                } else {
                    this.removeFile(file);
                    alert(response.data.message);
                }
            });

            this.on("error", function(file, message) {
                this.removeFile(file);
                alert(message);
            });

            this.on("addedfile", function(file) {
                // Optional: tampilkan preview atau informasi file
                console.log("File added:", file.name);
            });
        }
    });

    // Fungsi untuk menambahkan file ke tabel
    function addFileToList(importId, fileName) {
        const row = `
            <tr data-file-id="${importId}">
                <td class="column-filename">
                    <strong>${fileName}</strong>
                </td>
                <td class="column-progress">
                    <div class="stwi-progress">
                        <div class="stwi-progress-bar" style="width: 0%"></div>
                        <span class="stwi-progress-text">0%</span>
                    </div>
                </td>
                <td class="column-status">
                    <span class="stwi-status-badge stwi-status-pending">
                        Menunggu
                    </span>
                </td>
                <td class="column-actions">
                    <div class="stwi-file-controls">
                        <button class="button stwi-start-import">
                            <span class="dashicons dashicons-controls-play"></span>
                            Mulai
                        </button>
                        <button class="button stwi-delete-import">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        
    // Tambahkan row ke tbody
    $('#stwi-file-list').prepend(row);
    
    // Simpan ke localStorage untuk persistensi
    const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
    activeImports.push({
        id: importId,
        fileName: fileName,
        timestamp: new Date().getTime(),
        status: 'pending'
    });
    localStorage.setItem('stwi_active_imports', JSON.stringify(activeImports));
    
    // Mulai monitoring progress
    checkImportProgress(importId);
}


    // Tombol per file
    $(document).on("click", ".stwi-process-file", function() {
        const row = $(this).closest("tr");
        const importId = row.data("import-id");
        startImport(importId, row);
    });

    $(document).on("click", ".stwi-pause-file", function() {
        const row = $(this).closest("tr");
        pauseImport(row);
    });

    $(document).on("click", ".stwi-resume-file", function() {
        const row = $(this).closest("tr");
        resumeImport(row);
    });

    $(document).on("click", ".stwi-delete-file", function() {
        const row = $(this).closest("tr");
        if (confirm("Yakin ingin menghapus file ini?")) {
            // Jika ingin menghapus file fisik di server, tambahkan AJAX hapus di sini
            row.remove();
            updateStatistics();
        }
    });

    // Tombol Proses Semua File
    $("#stwi-process-all").on("click", function() {
        const rows = $("#stwi-file-list tr");
        if (rows.length === 0) {
            alert("Tidak ada file yang diunggah untuk diproses.");
            return;
        }
        rows.each(function() {
            const row = $(this);
            const importId = row.data("import-id");
            if (importId) {
                startImport(importId, row);
            }
        });
    });

    // Fungsi untuk mulai impor
    function startImport(importId, row) {
        row.find(".stwi-status")
           .removeClass("stwi-status-pending")
           .addClass("stwi-status-processing")
           .text("Memproses");

        row.find(".stwi-process-file").hide();
        row.find(".stwi-pause-file").show();

        processBatch(importId, row);
    }

    // Fungsi untuk jeda impor
    function pauseImport(row) {
        row.data("paused", true);
        row.find(".stwi-pause-file").hide();
        row.find(".stwi-resume-file").show();
    }

    // Fungsi untuk lanjutkan impor
    function resumeImport(row) {
        row.data("paused", false);
        row.find(".stwi-resume-file").hide();
        row.find(".stwi-pause-file").show();

        const importId = row.data("import-id");
        processBatch(importId, row);
    }

    // Fungsi untuk memproses batch
    function processBatch(importId, row) {
        if (row.data("paused") || !importId) return;

        $.ajax({
            url: stwi_params.ajax_url,
            type: "POST",
            data: {
                action: "stwi_process_batch",
                nonce: stwi_params.nonce,
                import_id: importId
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(response.data, row);
                    
                    if (!response.data.completed && !row.data("paused")) {
                        setTimeout(function() {
                            processBatch(importId, row);
                        }, 1000);
                    } else if (response.data.completed) {
                        row.find(".stwi-status")
                           .removeClass("stwi-status-processing")
                           .addClass("stwi-status-completed")
                           .text("Selesai");
                    }
                } else {
                    showError(response.data.message, row);
                }
            },
            error: function(xhr, status, error) {
                showError("Ajax error: " + error, row);
            }
        });
    }

    // Fungsi untuk memperbarui progress di baris file
    function updateProgress(importId, progress) {
        const row = $(`tr[data-file-id="${importId}"]`);
        if (row.length) {
            // Hitung persentase
            const percentage = Math.round((progress.processed / progress.total) * 100);
            
            // Update progress bar dan teks
            row.find('.stwi-progress-bar').css('width', `${percentage}%`);
            row.find('.stwi-progress-text').text(`${percentage}%`);
            row.find('.stwi-processed').text(progress.processed);
            row.find('.stwi-total').text(progress.total);
            
            // Update status badge
            const statusBadge = row.find('.stwi-status-badge');
            statusBadge.removeClass('stwi-status-pending stwi-status-processing stwi-status-completed');
            
            if (progress.completed) {
                // Update status jika selesai
                statusBadge.addClass('stwi-status-completed').text('Selesai');
                row.find('.stwi-start-import, .stwi-pause-import').hide();
                
                // Hapus dari localStorage
                const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
                const updatedImports = activeImports.filter(item => item.id !== importId);
                localStorage.setItem('stwi_active_imports', JSON.stringify(updatedImports));
                
                // Update statistik
                updateStatistics();
            } else {
                // Update status jika masih proses
                statusBadge.addClass('stwi-status-processing').text('Sedang Proses');
            }
            
            // Simpan progress ke localStorage
            const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
            const importIndex = activeImports.findIndex(item => item.id === importId);
            if (importIndex !== -1) {
                activeImports[importIndex].progress = progress;
                localStorage.setItem('stwi_active_imports', JSON.stringify(activeImports));
            }
        }
    }
    // Fungsi untuk menampilkan pesan error
    function showError(message, row) {
        row.find(".stwi-status")
           .removeClass("stwi-status-processing stwi-status-pending")
           .addClass("stwi-status-error")
           .text(message);
        updateStatistics();
    }

    // Tombol jeda, lanjut, dan batal di luar tabel (jika diperlukan)
    $("#stwi-pause").on("click", function() {
        isPaused = true;
        $(this).hide();
        $("#stwi-resume").show();
    });

    $("#stwi-resume").on("click", function() {
        isPaused = false;
        $(this).hide();
        $("#stwi-pause").show();
        // Panggil kembali processBatch(...) dengan importId yg sesuai
    });

    $("#stwi-cancel").on("click", function() {
        if (confirm("Yakin ingin membatalkan proses import?")) {
            // Set status importRunning = false dsb jika diperlukan
            $(".stwi-controls").hide();
            // Tampilkan pesan status dibatalkan
        }
    });

    function updateStatistics() {
        // Hitung total file
        const totalFiles = $("#stwi-file-list tr").length;
        $("#stwi-total-files").text(totalFiles);
        
        // Hitung file berhasil
        const successFiles = $("#stwi-file-list .stwi-status-completed").length;
        $("#stwi-success-count").text(successFiles);
        
        // Hitung file error
        const errorFiles = $("#stwi-file-list .stwi-status-error").length;
        $("#stwi-error-count").text(errorFiles);
    }

    //Code Baru
        // Fungsi untuk memperbarui tampilan progress
        function updateProgress(importId, progress) {
            const row = $(`tr[data-file-id="${importId}"]`);
            if (row.length) {
                const percentage = Math.round((progress.processed / progress.total) * 100);
                
                // Update progress bar dan teks
                row.find('.stwi-progress-bar').css('width', `${percentage}%`);
                row.find('.stwi-progress-text').text(`${percentage}%`);
                
                // Update status badge
                const statusBadge = row.find('.stwi-status-badge');
                statusBadge.removeClass('stwi-status-pending stwi-status-processing stwi-status-completed');
                
                if (progress.completed) {
                    statusBadge.addClass('stwi-status-completed').text('Selesai');
                    row.find('.stwi-start-import, .stwi-pause-import').hide();
                } else {
                    statusBadge.addClass('stwi-status-processing').text('Sedang Proses');
                }
            }
        }
    
        // Fungsi untuk menambahkan file ke daftar
        function addFileToList(importId, fileName) {
            const row = $($('#stwi-file-row-template').html()
                .replace(/{%=fileId%}/g, importId)
                .replace(/{%=fileName%}/g, fileName));
            $('#stwi-file-list').prepend(row);
            
            // Simpan ke localStorage
            const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
            activeImports.push({
                id: importId,
                fileName: fileName,
                timestamp: new Date().getTime()
            });
            localStorage.setItem('stwi_active_imports', JSON.stringify(activeImports));
            
            // Mulai monitoring progress
            checkImportProgress(importId);
        }


    
        // Fungsi untuk mengecek progress
        function checkImportProgress(importId) {
            $.ajax({
                url: stwi_params.ajax_url,
                method: 'POST',
                data: {
                    action: 'stwi_get_progress',
                    nonce: stwi_params.nonce,
                    import_id: importId
                },
                success: function(response) {
                    if (response.success) {
                        updateProgress(importId, response.data);
                        
                        // Simpan progress ke localStorage
                        const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
                        const importIndex = activeImports.findIndex(item => item.id === importId);
                        if (importIndex !== -1) {
                            activeImports[importIndex].progress = response.data;
                            localStorage.setItem('stwi_active_imports', JSON.stringify(activeImports));
                        }
    
                        if (!response.data.completed) {
                            setTimeout(() => checkImportProgress(importId), 5000);
                        }
                    }
                }
            });
        }

        // Event handler untuk tombol hapus
        $(document).on('click', '.stwi-delete-import', function() {
            if (confirm('Apakah Anda yakin ingin menghapus file ini?')) {
                const row = $(this).closest('tr');
                const importId = row.data('file-id');
                
                // Hapus dari localStorage
                const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
                const updatedImports = activeImports.filter(item => item.id !== importId);
                localStorage.setItem('stwi_active_imports', JSON.stringify(updatedImports));
                
                row.remove();
            }
        });

        function addFileToList(importId, fileName) {
            // Hanya simpan file yang sedang diproses
            const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
            
            // Batasi hanya menyimpan file yang belum selesai
            const newImport = {
                id: importId,
                fileName: fileName,
                timestamp: new Date().getTime(),
                status: 'processing'
            };
            
            // Tambahkan import baru
            activeImports.push(newImport);
            
            // Simpan kembali ke localStorage
            localStorage.setItem('stwi_active_imports', JSON.stringify(activeImports));
        }
        
        // Modifikasi fungsi updateProgress
        function updateProgress(importId, progress) {
            // ... kode yang sudah ada ...
            
            if (progress.completed) {
                // Hapus dari localStorage jika sudah selesai
                const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
                const updatedImports = activeImports.filter(item => item.id !== importId);
                localStorage.setItem('stwi_active_imports', JSON.stringify(updatedImports));
            }
        }

        // Tambahkan fungsi untuk membersihkan data lama
        function cleanupOldImports() {
            const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
            const currentTime = new Date().getTime();
            const ONE_HOUR = 3600000; // 1 jam dalam milidetik
            
            // Hanya simpan file yang belum selesai dan belum lebih dari 1 jam
            const updatedImports = activeImports.filter(item => {
                const isRecent = (currentTime - item.timestamp) < ONE_HOUR;
                return isRecent && item.status === 'processing';
            });
            
            localStorage.setItem('stwi_active_imports', JSON.stringify(updatedImports));
        }

        // Panggil saat halaman dimuat
        $(document).ready(function() {
            cleanupOldImports();
        });

        
        // Muat kembali impor yang aktif saat refresh
        const activeImports = JSON.parse(localStorage.getItem('stwi_active_imports') || '[]');
        if (activeImports.length > 0) {
            $('.stwi-files-section').show();
            activeImports.forEach(importItem => {
                if (importItem && importItem.id) {
                    addFileToList(importItem.id, importItem.fileName);
                    if (importItem.progress) {
                        updateProgress(importItem.id, importItem.progress);
                    }
                }
            });
        }
    
    // Inisialisasi Dropzone
    if ($("#stwi-upload-form").length) {
        const dropzone = new Dropzone("#stwi-upload-form", {
            url: stwi_params.ajax_url,
            paramName: "file",
            maxFilesize: 14,
            acceptedFiles: ".csv",
            autoProcessQueue: true,
            dictDefaultMessage: stwi_params.strings.dropzone_message,
            init: function() {
                this.on("sending", function(file, xhr, formData) {
                    formData.append("action", "stwi_upload_csv");
                    formData.append("nonce", stwi_params.nonce);
                });
                
                this.on("success", function(file, response) {
                    console.log('Upload response:', response);
                    if (response.success) {
                        $('.stwi-files-section').show();
                        addFileToList(response.data.import_id, file.name);
                    } else {
                        this.removeFile(file);
                        alert(response.data.message);
                    }
                });
            }
        });
    }
});