(function($) {
	'use strict';

	function escapeHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	var $form = $('#skiff-import-products-form');
	var $progressWrap = $('#skiff-import-progress-wrap');
	var $progressBar = $('#skiff-import-progress-bar');
	var $progressPercent = $('#skiff-import-progress-percent');
	var $progressText = $('#skiff-import-progress-text');
	var $resultsWrap = $('#skiff-import-results-wrap');
	var $submitBtn = $('#skiff-import-submit');

	$form.on('submit', function(e) {
		e.preventDefault();

		var fileInput = document.getElementById('csv_upload');
		if (!fileInput || !fileInput.files || !fileInput.files[0]) {
			alert('Please select a CSV file.');
			return;
		}

		var dryRun = document.getElementById('dry_run').checked;
		var formData = new FormData();
		formData.append('action', 'import_products_upload');
		formData.append('nonce', skiffImportProducts.nonce);
		formData.append('csv_upload', fileInput.files[0]);

		$submitBtn.prop('disabled', true);
		$resultsWrap.hide();
		$progressWrap.show();
		$progressBar.css('width', '0%');
		$progressPercent.text('0%');
		$progressText.text('Uploading...');

		$.ajax({
			url: skiffImportProducts.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function(response) {
				if (response.success && response.data.temp_path) {
					processBatches(response.data.temp_path, response.data.total_rows, response.data.batch_size, dryRun);
				} else {
					showError(response.data && response.data.message ? response.data.message : 'Upload failed.');
					$submitBtn.prop('disabled', false);
					$progressWrap.hide();
				}
			},
			error: function() {
				showError('Upload failed. Please try again.');
				$submitBtn.prop('disabled', false);
				$progressWrap.hide();
			}
		});
	});

	function processBatches(tempPath, totalRows, batchSize, dryRun) {
		var offset = 0;
		var totalCreated = 0;
		var totalSkippedExisting = [];
		var totalErrors = [];
		var reportRows = [];

		function processNext() {
			$progressText.text('Processing rows ' + (offset + 1) + ' - ' + Math.min(offset + batchSize, totalRows) + ' of ' + totalRows + '...');

			$.ajax({
				url: skiffImportProducts.ajaxUrl,
				type: 'POST',
				data: {
					action: 'import_products_batch',
					nonce: skiffImportProducts.nonce,
					temp_path: tempPath,
					offset: offset,
					dry_run: dryRun ? '1' : '0'
				},
				success: function(response) {
					if (response.success && response.data) {
						totalCreated += response.data.created || 0;
						if (response.data.skipped_existing && response.data.skipped_existing.length) {
							totalSkippedExisting = totalSkippedExisting.concat(response.data.skipped_existing);
						}
						if (response.data.errors && response.data.errors.length) {
							totalErrors = totalErrors.concat(response.data.errors);
						}
						if (response.data.report_rows && response.data.report_rows.length) {
							reportRows = reportRows.concat(response.data.report_rows);
						}

						offset += batchSize;
						var percent = totalRows > 0 ? Math.min(100, Math.round((offset / totalRows) * 100)) : 100;
						$progressBar.css('width', percent + '%');
						$progressPercent.text(percent + '%');

						if (response.data.has_more) {
							processNext();
						} else {
							showResults(totalCreated, totalSkippedExisting, totalErrors, dryRun, reportRows);
							$submitBtn.prop('disabled', false);
							$progressWrap.hide();
						}
					} else {
						showError(response.data && response.data.error ? response.data.error : 'Batch processing failed.');
						$submitBtn.prop('disabled', false);
						$progressWrap.hide();
					}
				},
				error: function() {
					showError('Batch processing failed. Please try again.');
					$submitBtn.prop('disabled', false);
					$progressWrap.hide();
				}
			});
		}

		processNext();
	}

	function escapeCsvValue(val) {
		if (val === null || val === undefined) return '';
		var str = String(val);
		if (str.indexOf('"') !== -1 || str.indexOf(',') !== -1 || str.indexOf('\n') !== -1) {
			return '"' + str.replace(/"/g, '""') + '"';
		}
		return str;
	}

	function buildReportCsv(reportRows) {
		var headers = ['sku', 'name', 'status', 'product_id'];
		var lines = [headers.join(',')];
		reportRows.forEach(function(row) {
			var cells = [
				escapeCsvValue(row.sku),
				escapeCsvValue(row.name),
				escapeCsvValue(row.status),
				escapeCsvValue(row.product_id)
			];
			lines.push(cells.join(','));
		});
		// BOM for Excel UTF-8 compatibility
		return '\uFEFF' + lines.join('\n');
	}

	function downloadReportCsv(reportRows) {
		var csv = buildReportCsv(reportRows);
		var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		var link = document.createElement('a');
		var url = URL.createObjectURL(blob);
		var filename = 'import-products-report-' + new Date().toISOString().slice(0, 10) + '.csv';
		link.setAttribute('href', url);
		link.setAttribute('download', filename);
		link.style.visibility = 'hidden';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(url);
	}

	function showResults(created, skippedExisting, errors, dryRun, reportRows) {
		reportRows = reportRows || [];
		var html = '<div class="notice notice-success is-dismissible"><p><strong>' + (dryRun ? 'Dry Run Complete!' : 'Import Complete!') + '</strong><br>';
		html += (dryRun ? 'Would Create: ' : 'Products Created: ') + created + '<br>';
		html += 'Skipped (SKU already exists): ' + skippedExisting.length + '<br>';
		html += 'Errors: ' + errors.length + '</p></div>';

		if (reportRows.length > 0) {
			html += '<div class="notice notice-info"><p>';
			html += '<strong>Report generated.</strong> ';
			html += '<button type="button" class="button button-secondary" id="skiff-download-import-report">Download Report CSV</button>';
			html += '</p></div>';
		}

		if (errors.length > 0) {
			html += '<div class="notice notice-error"><h3>Errors (' + errors.length + '):</h3><ul style="max-height: 200px; overflow-y: auto;">';
			errors.slice(0, 50).forEach(function(err) {
				html += '<li>Line ' + escapeHtml(String(err.line)) + ' (SKU: ' + escapeHtml(err.sku) + '): ' + escapeHtml(err.error) + '</li>';
			});
			if (errors.length > 50) {
				html += '<li><em>... and ' + (errors.length - 50) + ' more</em></li>';
			}
			html += '</ul></div>';
		}

		$resultsWrap.html(html).show();

		if (reportRows.length > 0) {
			$resultsWrap.off('click', '#skiff-download-import-report').on('click', '#skiff-download-import-report', function() {
				downloadReportCsv(reportRows);
			});
		}
	}

	function showError(message) {
		$resultsWrap.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + escapeHtml(message) + '</p></div>').show();
	}
})(jQuery);
