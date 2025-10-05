
// assets/js/uploader.js
// currentUploads tömböt nem használjuk aktívan ebben a verzióban, de meghagyható jövőbeli fejlesztéshez
// let currentUploads = []; 

// Globális változók a sorbaállításhoz
let uploadGlobalQueue = []; // A teljes várólista {file, id, status, domElement}
let isGloballyUploading = false; // Van-e aktív feltöltés a sorból
let totalFilesToUpload = 0;
let filesUploadedSuccessfully = 0;
let filesFailedToUpload = 0;
// UI elemek
const uploadArea = document.getElementById('uploadArea');
const fileInput = document.getElementById('fileInput');
const folderInput = document.getElementById('folderInput');

const overallProgressContainer = document.getElementById('uploadQueueOverallProgress');
const overallProgressFill = document.getElementById('overallProgressFill');
const overallProgressText = document.getElementById('overallProgressText');
const overallFilesProcessedText = document.getElementById('overallFilesProcessedText');

const queueItemsContainer = document.getElementById('uploadQueueItemsContainer');

const currentFileProgressContainer = document.getElementById('currentFileProgressContainer');
const currentFileNameText = document.getElementById('currentFileNameText');
const currentFileProgressFill = document.getElementById('currentFileProgressFill');
const currentFileProgressText = document.getElementById('currentFileProgressText');
const currentFileSpeedText = document.getElementById('currentFileSpeedText');
const currentFileChunkInfoText = document.getElementById('currentFileChunkInfoText');
const voiceControls = document.getElementById('voiceControls');
let mediaRecorder; // Ezek maradnak a hangrögzítéshez
let audioChunks = [];

// Drag & Drop
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    uploadArea.addEventListener(eventName, () => uploadArea.classList.add('drag-over'), false);
});

['dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, () => uploadArea.classList.remove('drag-over'), false);
});

uploadArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files.length > 0) {
        handleFiles(files);
    }
}

fileInput.addEventListener('change', (e) => handleFiles(e.target.files));
folderInput.addEventListener('change', (e) => handleFiles(e.target.files));

// Clipboard paste
document.addEventListener('paste', function(e) {
    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            const blob = items[i].getAsFile();
            if (blob) {
                const file = new File([blob], `clipboard-${Date.now()}.${blob.type.split('/')[1] || 'png'}`, {type: blob.type});
                handleFiles([file]);
                break;
            }
        }
    }
});

function pasteFromClipboard() {
    if (navigator.clipboard && navigator.clipboard.read) {
        navigator.clipboard.read().then(items => {
            for (const item of items) {
                for (const type of item.types) {
                    if (type.startsWith('image/')) {
                        item.getType(type).then(blob => {
                            const file = new File([blob], `clipboard-${Date.now()}.${blob.type.split('/')[1] || 'png'}`, {type: blob.type});
                            handleFiles([file]);
                        });
                        return; // Csak az első képet dolgozzuk fel
                    }
                }
            }
            // Ha nem volt kép, opcionálisan jelezhetünk
            // showNotification('Nincs kép a vágólapon.', 'info');
        }).catch(err => {
            console.error('Clipboard read error:', err);
            showNotification('Vágólap hozzáférés nem engedélyezett vagy hiba történt.', 'error');
        });
    } else {
        showNotification('A böngésző nem támogatja a vágólap biztonságos olvasását.', 'warning');
    }
}

// Hangjegyzet rögzítés
function startVoiceRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showNotification('A hangrögzítés nem támogatott ezen a böngészőn.', 'error');
        return;
    }
    
    navigator.mediaDevices.getUserMedia({ audio: true })
        .then(stream => {
            mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' }); // webm általában jobban támogatott
            audioChunks = [];
            
            mediaRecorder.ondataavailable = event => {
                audioChunks.push(event.data);
            };
            
            mediaRecorder.onstop = () => {
                const audioBlob = new Blob(audioChunks, { type: mediaRecorder.mimeType });
                const fileExtension = mediaRecorder.mimeType.split('/')[1].split(';')[0]; // pl. webm
                const fileName = `voice-note-${Date.now()}.${fileExtension}`;
                const file = new File([audioBlob], fileName, {type: mediaRecorder.mimeType});
                handleFiles([file]);
                
                stream.getTracks().forEach(track => track.stop());
                voiceControls.classList.remove('recording');
            };
            
            mediaRecorder.start();
            voiceControls.classList.add('recording');
        })
        .catch(err => {
            console.error('Microphone access error:', err);
            showNotification('Mikrofon hozzáférés megtagadva vagy hiba: ' + err.message, 'error');
            voiceControls.classList.remove('recording');
        });
}

function stopVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
    }
    // A 'recording' class eltávolítását az onstop esemény végzi
}

function cancelVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop(); // Leállítja a rögzítést, de az onstop nem fogja feldolgozni az adatot, mert az audioChunks üres lesz
    }
    audioChunks = []; // Töröljük a rögzített adatokat
    if(mediaRecorder && mediaRecorder.stream) { // Állítsuk le a stream trackeket
         mediaRecorder.stream.getTracks().forEach(track => track.stop());
    }
    voiceControls.classList.remove('recording');
}

function handleFiles(files) {
    if (!files || files.length === 0) return;
    
    totalFilesToUpload += files.length;
    overallProgressContainer.style.display = 'block';
    updateOverallProgress();
    Array.from(files).forEach(file => {
        const uniqueFileId = 'file-uid-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        const domElement = createFileQueueItemDOM(file, uniqueFileId);
        queueItemsContainer.appendChild(domElement);
        uploadGlobalQueue.push({ file: file, id: uniqueFileId, status: 'queued', domElement: domElement });
    });
    if (!isGloballyUploading) {
        processNextFileInGlobalQueue();
    }
}
function createFileQueueItemDOM(file, uniqueFileId) {
    const itemDiv = document.createElement('div');
    itemDiv.id = 'queue-item-' + uniqueFileId;
    itemDiv.className = 'upload-queue-item';
    itemDiv.style.cssText = `
        background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.15); 
        border-radius: 8px; padding: 10px 15px; margin-bottom: 8px; 
        display: flex; justify-content: space-between; align-items: center;
        transition: opacity 0.5s ease;
    `;
    
    const fileName = file.name.length > 50 ? file.name.substring(0, 47) + '...' : file.name;
    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
    itemDiv.innerHTML = `
        <div style="flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-right: 15px;">
            <span class="file-name" title="${file.name}">${fileName}</span>
            <span class="file-size" style="font-size:0.8em; color:#8892b0; margin-left:5px;">(${fileSizeMB} MB)</span>
        </div>
        <div class="status-indicator" style="font-size:0.85em; color:#8892b0; min-width:80px; text-align:right;">Várakozik</div>
    `;
    return itemDiv;
}

function updateQueueItemIndicator(uniqueFileId, text, color = '#8892b0', isFinal = false) {
    const itemDiv = document.getElementById('queue-item-' + uniqueFileId);
    if (itemDiv) {
        const indicator = itemDiv.querySelector('.status-indicator');
        if (indicator) {
            indicator.innerHTML = text; // Lehet HTML is (pl. link)
            indicator.style.color = color;
        }
        if (isFinal) {
            // Késleltetett eltávolítás vagy áttetszővé tétel
            setTimeout(() => { 
                itemDiv.style.opacity = '0.5';
                // itemDiv.remove(); // Vagy teljesen eltávolítjuk
            }, isFinal === 'error' ? 10000 : 5000); // Hibánál tovább látható
        }
    }
}
function updateOverallProgress() {
    const filesProcessed = filesUploadedSuccessfully + filesFailedToUpload;
    const percent = totalFilesToUpload > 0 ? (filesProcessed / totalFilesToUpload) * 100 : 0;
    overallProgressFill.style.width = percent.toFixed(2) + '%';
    overallProgressText.textContent = Math.round(percent) + '%';
    overallFilesProcessedText.textContent = `${filesProcessed}/${totalFilesToUpload} fájl feldolgozva`;
    if (filesProcessed === totalFilesToUpload && totalFilesToUpload > 0) {
         setTimeout(() => { // Adjunk időt az utolsó fájl státuszának frissítésére
            overallFilesProcessedText.textContent += ` (Sikeres: ${filesUploadedSuccessfully}, Hibás: ${filesFailedToUpload})`;
            // A teljes progress bar elrejtése vagy egy "Kész" állapot mutatása
            // overallProgressContainer.style.display = 'none'; 
            // totalFilesToUpload = 0; filesUploadedSuccessfully = 0; filesFailedToUpload = 0; // Reset
         }, 1000);
    }
}
function processNextFileInGlobalQueue() {
    if (uploadGlobalQueue.length === 0) {
        isGloballyUploading = false;
        currentFileProgressContainer.style.display = 'none'; // Aktuális fájl progress elrejtése
        updateOverallProgress(); // Végső összesített progress frissítése
        return;
    }
    isGloballyUploading = true;
    const uploadTask = uploadGlobalQueue.shift(); // Kivesszük az elsőt
    
    updateQueueItemIndicator(uploadTask.id, 'Feltöltés...', '#3498db');
    
    // Aktuális fájl progress UI frissítése
    currentFileProgressContainer.style.display = 'block';
    currentFileNameText.textContent = uploadTask.file.name;
    currentFileProgressFill.style.width = '0%';
    currentFileProgressText.textContent = '0%';
    currentFileSpeedText.textContent = '0 KB/s';
    currentFileChunkInfoText.textContent = '';
    const MAX_SIZE_FOR_DIRECT_UPLOAD_JS = 1024 * 1024 * 20; // 20MB
    if (uploadTask.file.size > MAX_SIZE_FOR_DIRECT_UPLOAD_JS) {
        uploadFileInChunks(uploadTask.file, uploadTask.id);
    } else {
        uploadSingleFileDirectly(uploadTask.file, uploadTask.id);
    }
}
function generateUniqueFileId() { // Kliens oldali UUIDv4 generátor
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
}
function uploadFileInChunks(file, uiUniqueId) {
    const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    let currentChunkIndex = 0;
    const fileIdForServer = generateUniqueFileId(); // Ez az ID megy a szervernek a darabokhoz
    let startTimeForFile = Date.now();
    let uploadedBytesForFile = 0;
    currentFileChunkInfoText.textContent = `Darab: 0/${totalChunks}`;

    const urlParams = new URLSearchParams(window.location.search);
    const uploadToken = urlParams.get('token');
    if (!uploadToken) {
        // Ezt a hibát a PHP oldalnak kellene kezelnie, de kliens oldalon is leállíthatjuk.
        filesFailedToUpload++;
        updateQueueItemIndicator(uiUniqueId, `Hiba: Hiányzó feltöltési token az URL-ben.`, '#e74c3c', 'error');
        processNextFileInGlobalQueue();
        return;
    }

    function sendNextChunk() {
        if (currentChunkIndex >= totalChunks) return; // Kész
        const start = currentChunkIndex * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const chunkBlob = file.slice(start, end);
        const formData = new FormData();
        formData.append('file', chunkBlob, file.name);
        formData.append('chunk', currentChunkIndex);
        formData.append('chunks', totalChunks);
        formData.append('name', file.name);
        formData.append('file_id', fileIdForServer); // Fontos: ezt az ID-t használja a szerver
        formData.append('upload_token', uploadToken); // Token hozzáadása
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'UploadHandler.php', true);
        xhr.upload.onprogress = function(event) {
            if (event.lengthComputable) {
                let chunkProgressPercent = (event.loaded / event.total) * 100;
                let overallUploadedForThisFile = uploadedBytesForFile + event.loaded;
                let overallPercentForThisFile = (overallUploadedForThisFile / file.size) * 100;
                
                const elapsedSeconds = (Date.now() - startTimeForFile) / 1000;
                const speed = elapsedSeconds > 0 ? overallUploadedForThisFile / elapsedSeconds : 0;
                currentFileProgressFill.style.width = overallPercentForThisFile.toFixed(2) + '%';
                currentFileProgressText.textContent = Math.round(overallPercentForThisFile) + '%';
                currentFileSpeedText.textContent = formatSpeed(speed);
            }
        };
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.error) {
                        filesFailedToUpload++;
                        updateQueueItemIndicator(uiUniqueId, `Hiba: ${response.error}`, '#e74c3c', 'error');
                        processNextFileInGlobalQueue();
                        return;
                    }
                    if (response.success && response.view_url) { // Utolsó chunk, sikeres összeállítás
                        filesUploadedSuccessfully++;
                        updateQueueItemIndicator(uiUniqueId, `<a href="${response.view_url}" target="_blank" style="color:#2ecc71;text-decoration:none;">Sikeres! Megtekintés</a>`, '#2ecc71', 'success');
                        currentFileProgressFill.style.width = '100%'; // Biztos 100%
                        currentFileProgressText.textContent = '100%';
                        currentFileChunkInfoText.textContent = 'Kész!';
                        processNextFileInGlobalQueue();
                        showSuccess(response);
                    } else if (response.success && response.chunk_uploaded !== undefined) {
                        uploadedBytesForFile += chunkBlob.size; // Csak a sikeresen feltöltött chunk méretét adjuk hozzá
                        currentChunkIndex++;
                        currentFileChunkInfoText.textContent = `Darab: ${currentChunkIndex}/${totalChunks}`;
                        if (currentChunkIndex < totalChunks) {
                            sendNextChunk();
                        } else {
                            // Elvileg ide nem szabadna befutnia, ha az utolsó chunk a view_url-t adja vissza
                            console.warn("Chunked upload finished but no final success response from server for file: " + file.name);
                             filesFailedToUpload++; // Inkonzisztens állapot
                             updateQueueItemIndicator(uiUniqueId, `Hiba: Szerver nem erősítette meg a fájl összeállítását.`, '#e74c3c', 'error');
                             processNextFileInGlobalQueue();
                        }
                    } else {
                        filesFailedToUpload++;
                        updateQueueItemIndicator(uiUniqueId, 'Váratlan szerver válasz (chunk).', '#e74c3c', 'error');
                        processNextFileInGlobalQueue();
                    }
                } catch (e) {
                    filesFailedToUpload++;
                    updateQueueItemIndicator(uiUniqueId, 'Szerver válasz feldolgozási hiba (chunk).', '#e74c3c', 'error');
                    processNextFileInGlobalQueue();
                }
            } else {
                filesFailedToUpload++;
                updateQueueItemIndicator(uiUniqueId, `HTTP hiba: ${xhr.status} (chunk).`, '#e74c3c', 'error');
                processNextFileInGlobalQueue();
            }
        };
        xhr.onerror = function() {
            filesFailedToUpload++;
            updateQueueItemIndicator(uiUniqueId, 'Hálózati hiba (chunk).', '#e74c3c', 'error');
            processNextFileInGlobalQueue();
        };
        xhr.send(formData);
    }

    sendNextChunk(); // Első chunk indítása
}
function uploadSingleFileDirectly(file, uiUniqueId) {
    const formData = new FormData();
    // A direct feltöltésnél is küldhetünk egyedi ID-t, ha a PHP oldal ezt kezeli.
    // Jelenleg az index.php a direct feltöltésnél maga generálja az ID-t.
    // Ha konzisztens ID kezelést akarunk (ajánlott), akkor itt is küldeni kellene:
    // const fileIdForServer = generateUniqueFileId();
    // formData.append('file_id', fileIdForServer); 
    formData.append('file', file);
    const urlParams = new URLSearchParams(window.location.search);
    const uploadToken = urlParams.get('token');
    if (!uploadToken) {
        filesFailedToUpload++;
        updateQueueItemIndicator(uiUniqueId, `Hiba: Hiányzó feltöltési token az URL-ben.`, '#e74c3c', 'error');
        processNextFileInGlobalQueue();
        return;
    }
    formData.append('upload_token', uploadToken); // Token hozzáadása
    const xhr = new XMLHttpRequest();
    let startTimeForFile = Date.now();
    currentFileChunkInfoText.textContent = ''; // Nincs darab info
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            const elapsedSeconds = (Date.now() - startTimeForFile) / 1000;
            const speed = elapsedSeconds > 0 ? e.loaded / elapsedSeconds : 0;
            
            currentFileProgressFill.style.width = percentComplete.toFixed(2) + '%';
            currentFileProgressText.textContent = Math.round(percentComplete) + '%';
            currentFileSpeedText.textContent = formatSpeed(speed);
        }
    };
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success && response.view_url) {
                    filesUploadedSuccessfully++;
                    updateQueueItemIndicator(uiUniqueId, `<a href="${response.view_url}" target="_blank" style="color:#2ecc71;text-decoration:none;">Sikeres! Megtekintés</a>`, '#2ecc71', 'success');
                    currentFileProgressFill.style.width = '100%';
                    currentFileProgressText.textContent = '100%';
                    showSuccess(response);
                } else {
                    filesFailedToUpload++;
                    updateQueueItemIndicator(uiUniqueId, `Hiba: ${response.error || 'Ismeretlen'}`, '#e74c3c', 'error');
                }
            } catch (e) {
                filesFailedToUpload++;
                updateQueueItemIndicator(uiUniqueId, 'Szerver válasz feldolgozási hiba.', '#e74c3c', 'error');
            }
        } else {
            filesFailedToUpload++;
            updateQueueItemIndicator(uiUniqueId, `HTTP hiba: ${xhr.status}.`, '#e74c3c', 'error');
        }
        processNextFileInGlobalQueue(); // Tovább a következőre
    };
    xhr.onerror = function() {
        filesFailedToUpload++;
        updateQueueItemIndicator(uiUniqueId, 'Hálózati hiba.', '#e74c3c', 'error');
        processNextFileInGlobalQueue();
    };
    xhr.open('POST', 'UploadHandler.php', true);
    xhr.send(formData);
}
function showProgress() {
    progressContainer.style.display = 'block';
    progressFill.style.width = '0%';
    progressText.textContent = '0%';
    speedText.textContent = '0 KB/s';
    chunkInfoText.textContent = '';
}

function hideProgress() {
    // Nem rejtjük el azonnal, a showSuccess/showError majd kezeli, ha kell
    // progressContainer.style.display = 'none'; 
}

function updateProgress(percent, speed) {
    progressFill.style.width = Math.min(100, percent).toFixed(2) + '%';
    progressText.textContent = Math.round(Math.min(100, percent)) + '%';
    
    let speedTextValue = '';
    if (speed < 1024) {
        speedTextValue = Math.round(speed) + ' B/s';
    } else if (speed < 1024 * 1024) {
        speedTextValue = (speed / 1024).toFixed(1) + ' KB/s';
    } else {
        speedTextValue = (speed / (1024 * 1024)).toFixed(2) + ' MB/s';
    }
    speedText.textContent = speedTextValue;
}

function showSuccess(response) {
    const successDiv = document.createElement('div');
    successDiv.style.cssText = "background: rgba(46, 204, 113, 0.2); border: 1px solid #2ecc71; border-radius: 10px; padding: 20px; margin-top: 20px; text-align: center; position: relative;";
    
    let qrCodeHTML = '';
    if (response.qr_code) {
        qrCodeHTML = `<div style="margin-bottom: 15px;">
                <img src="${response.qr_code}" alt="QR Kód" title="QR Kód a fájlhoz" style="width: 150px; height: 150px; border: 1px solid #ccc; border-radius: 5px; background: white; padding: 5px;">
            </div>`;
    }
    successDiv.innerHTML = `
        <button onclick="this.parentElement.remove(); hideProgress();" style="position: absolute; top: 10px; right: 10px; background: transparent; border: none; font-size: 1.5rem; color: #aaa; cursor: pointer; line-height:1;">×</button>
        <h3 style="color: #2ecc71; margin-bottom: 15px;">✅ Feltöltés sikeres!</h3>
        <p style="margin-bottom: 10px;">Fájl ID: <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px;">${response.file_id}</code></p>
        ${qrCodeHTML}
        <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
            <a href="${response.view_url}" target="_blank" class="btn">👁️ Megtekintés</a>
            <button class="btn btn-secondary" onclick="copyToClipboard('${response.view_url.startsWith('http') ? response.view_url : window.location.origin + '/' + response.view_url }')">📋 Link másolás</button>
        </div>
    `;
    
    uploadArea.appendChild(successDiv);
    progressContainer.style.display = 'none'; // Elrejtjük a progress bart siker esetén
}

function showError(message) {
    const errorDiv = document.createElement('div');
     errorDiv.style.cssText = "background: rgba(231, 76, 60, 0.2); border: 1px solid #e74c3c; border-radius: 10px; padding: 20px; margin-top: 20px; text-align: center; position: relative;";
    errorDiv.innerHTML = `
         <button onclick="this.parentElement.remove(); hideProgress();" style="position: absolute; top: 10px; right: 10px; background: transparent; border: none; font-size: 1.5rem; color: #aaa; cursor: pointer; line-height:1;">×</button>
        <h3 style="color: #e74c3c; margin-bottom: 10px;">❌ Hiba történt</h3>
        <p style="color: #ffffff;">${message}</p>
    `;
    uploadArea.appendChild(errorDiv);
    progressContainer.style.display = 'none'; // Elrejtjük a progress bart hiba esetén
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('✅ Link vágólapra másolva!', 'success');
    }).catch(err => {
        console.error('Failed to copy: ', err);
        showNotification('❌ Másolás sikertelen!', 'error');
    });
}
function showNotification(message, type = 'info') {
    const bgColor = type === 'success' ? '#2ecc71' : (type === 'error' ? '#e74c3c' : '#3498db');
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        background-color: ${bgColor}; color: white; padding: 15px 20px 15px 20px;
        border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 1000;
        min-width: 250px; max-width: 90%; text-align: center;
    `;
    // Bezáró gomb
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '×';
    closeBtn.style.cssText = `
        position: absolute; top: 5px; right: 10px;
        background: transparent; border: none;
        color: white; font-size: 1.2rem; cursor: pointer;
        line-height: 1;
    `;
    closeBtn.addEventListener('click', () => {
        notification.remove(); // manuális bezárás
    });
    // Tartalom
    const contentWrapper = document.createElement('div');
    contentWrapper.textContent = message;
    contentWrapper.style.paddingRight = '20px';
    notification.style.position = 'relative'; // fontos az absolute X-hez
    notification.appendChild(closeBtn);
    notification.appendChild(contentWrapper);
    document.body.appendChild(notification);
    // Automatikus eltűnés 3 másodperc után
    setTimeout(() => {
        if (document.body.contains(notification)) {
            notification.remove(); // teljes eltávolítás
        }
    }, 3000);
}
// Segédfüggvény a sebesség formázásához
function formatSpeed(bytesPerSecond) {
    if (bytesPerSecond < 1024) return Math.round(bytesPerSecond) + ' B/s';
    else if (bytesPerSecond < 1024 * 1024) return (bytesPerSecond / 1024).toFixed(1) + ' KB/s';
    else return (bytesPerSecond / (1024 * 1024)).toFixed(2) + ' MB/s';
}
// UUID generátor (ha a PHP nem küld vissza, vagy a chunkokhoz kell kliens oldalon)
function generateUniqueFileId() { 
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
}

// A showSuccess, showError, copyToClipboard, showNotification, keyboard shortcuts maradnak
// De a showSuccess/showError-t már nem a feltöltő függvények hívják közvetlenül.
// A showNotification továbbra is hasznos lehet pl. másoláshoz.

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ne aktiválódjon, ha input mezőben vagyunk
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
        return;
    }
    if (e.ctrlKey && (e.key === 'v' || e.key === 'V')) {
        e.preventDefault();
        pasteFromClipboard();
    }
    
    if (e.ctrlKey && (e.key === 'u' || e.key === 'U')) {
        e.preventDefault();
        fileInput.click();
    }
});
        