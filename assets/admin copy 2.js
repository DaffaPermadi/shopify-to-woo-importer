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

    // Fungsi addFileToList tetap sama
    function addFileToList(importId, fileName) {
        const row = `
            <tr data-import-id="${importId}">
                <td>${fileName}</td>
                <td>
                    <div class="stwi-progress">
                        <div class="stwi-progress-bar" style="width:0%;"></div>
                    </div>
                    <p>
                        Diproses: <span class="stwi-processed">0</span> /
                        <span class="stwi-total">0</span>
                    </p>
                </td>
                <td>
                    <span class="stwi-status stwi-status-pending">Menunggu</span>
                </td>
                <td class="stwi-file-controls">
                    <button class="button stwi-process-file">
                        <span class="dashicons dashicons-controls-play"></span>
                        Proses
                    </button>
                    <button class="button stwi-pause-file" style="display:none">
                        <span class="dashicons dashicons-controls-pause"></span>
                        Jeda
                    </button>
                    <button class="button stwi-resume-file" style="display:none">
                        <span class="dashicons dashicons-controls-play"></span>
                        Lanjut
                    </button>
                    <button class="button stwi-delete-file">
                        <span class="dashicons dashicons-trash"></span>
                        Hapus
                    </button>
                </td>
            </tr>
        `;
        $("#stwi-file-list").append(row);
        updateStatistics();
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
    function updateProgress(data, row) {
        const percent = Math.round((data.processed / data.total) * 100);
        row.find(".stwi-progress-bar").css("width", percent + "%");
        row.find(".stwi-processed").text(data.processed);
        row.find(".stwi-total").text(data.total);

        if (data.completed) {
            row.find(".stwi-status")
               .removeClass("stwi-status-processing")
               .addClass("stwi-status-completed")
               .text("Selesai");
            updateStatistics(); // Perbarui statistik setelah selesai
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
});