(function($) {
	'use strict';

	function escapeHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	var $form = $('#skiff-stock-update-form');
	var $progressWrap = $('#skiff-stock-progress-wrap');
	var $progressBar = $('#skiff-stock-progress-bar');
	var $progressPercent = $('#skiff-stock-progress-percent');
	var $progressText = $('#skiff-stock-progress-text');
	var $resultsWrap = $('#skiff-stock-results-wrap');
	var $submitBtn = $('#skiff-stock-submit');

	$form.on('submit', function(e) {
		e.preventDefault();

		var fileInput = document.getElementById('csv_upload');
		if (!fileInput || !fileInput.files || !fileInput.files[0]) {
			alert('Please select a CSV file.');
			return;
		}

		var dryRun = document.getElementById('dry_run').checked;
		var formData = new FormData();
		formData.append('action', 'stock_update_upload');
		formData.append('nonce', skiffStockUpdate.nonce);
		formData.append('csv_upload', fileInput.files[0]);

		$submitBtn.prop('disabled', true);
		$resultsWrap.hide();
		$progressWrap.show();
		$progressBar.css('width', '0%');
		$progressPercent.text('0%');
		$progressText.text('Uploading...');

		$.ajax({
			url: skiffStockUpdate.ajaxUrl,
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
		var totalUpdated = 0;
		var totalNotFound = [];
		var totalErrors = [];
		var reportRows = [];

		function processNext() {
			$progressText.text('Processing rows ' + (offset + 1) + ' - ' + Math.min(offset + batchSize, totalRows) + ' of ' + totalRows + '...');

			$.ajax({
				url: skiffStockUpdate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'stock_update_batch',
					nonce: skiffStockUpdate.nonce,
					temp_path: tempPath,
					offset: offset,
					dry_run: dryRun ? '1' : '0'
				},
				success: function(response) {
					if (response.success && response.data) {
						totalUpdated += response.data.updated || 0;
						if (response.data.not_found && response.data.not_found.length) {
							totalNotFound = totalNotFound.concat(response.data.not_found);
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
							showResults(totalUpdated, totalNotFound, totalErrors, dryRun, reportRows);
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
		var headers = ['product_name', 'sku', 'price', 'is_updated', 'sku_found'];
		var lines = [headers.join(',')];
		reportRows.forEach(function(row) {
			var cells = [
				escapeCsvValue(row.product_name),
				escapeCsvValue(row.sku),
				escapeCsvValue(row.price),
				escapeCsvValue(row.is_updated),
				escapeCsvValue(row.sku_found)
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
		var filename = 'stock-update-report-' + new Date().toISOString().slice(0, 10) + '.csv';
		link.setAttribute('href', url);
		link.setAttribute('download', filename);
		link.style.visibility = 'hidden';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(url);
	}

	function showResults(updated, notFound, errors, dryRun, reportRows) {
		reportRows = reportRows || [];
		var html = '<div class="notice notice-success is-dismissible"><p><strong>' + (dryRun ? 'Dry Run Complete!' : 'Update Complete!') + '</strong><br>';
		html += 'Products Updated: ' + updated + '<br>';
		html += 'SKUs Not Found: ' + notFound.length + '<br>';
		html += 'Errors: ' + errors.length + '</p></div>';

		if (reportRows.length > 0) {
			html += '<div class="notice notice-info"><p>';
			html += '<strong>Report generated.</strong> ';
			html += '<button type="button" class="button button-secondary" id="skiff-download-report">Download Report CSV</button>';
			html += '</p></div>';
		}

		if (notFound.length > 0) {
			html += '<div class="notice notice-warning"><h3>SKUs Not Found (' + notFound.length + '):</h3><ul style="max-height: 200px; overflow-y: auto;">';
			notFound.slice(0, 50).forEach(function(item) {
				html += '<li>Line ' + escapeHtml(String(item.line)) + ': ' + escapeHtml(item.sku) + '</li>';
			});
			if (notFound.length > 50) {
				html += '<li><em>... and ' + (notFound.length - 50) + ' more</em></li>';
			}
			html += '</ul></div>';
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
			$resultsWrap.off('click', '#skiff-download-report').on('click', '#skiff-download-report', function() {
				downloadReportCsv(reportRows);
			});
		}
	}

	function showError(message) {
		$resultsWrap.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + escapeHtml(message) + '</p></div>').show();
	}
})(jQuery);
