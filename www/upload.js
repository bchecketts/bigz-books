// Uploads each dropped or chosen file to upload.php in its own request, with a progress bar per file
const drop = document.getElementById('drop');
const picker = document.getElementById('picker');
const progressList = document.getElementById('progress');

drop.addEventListener('dragover', event => { event.preventDefault(); drop.classList.add('over'); });
drop.addEventListener('dragleave', () => drop.classList.remove('over'));
drop.addEventListener('drop', event => {
  event.preventDefault();
  drop.classList.remove('over');
  uploadAll(event.dataTransfer.files);
});
picker.addEventListener('change', () => { uploadAll(picker.files); picker.value = ''; });

async function uploadAll(files) {
  for (const file of files) {
    await uploadOne(file);
  }
}

function uploadOne(file) {
  const row = document.createElement('li');
  const name = document.createElement('span');
  const status = document.createElement('span');
  const bar = document.createElement('progress');
  name.textContent = file.name;
  bar.max = 1;
  bar.value = 0;
  status.appendChild(bar);
  row.append(name, status);
  progressList.prepend(row);

  return new Promise(resolve => {
    const form = new FormData();
    form.append('files[]', file);
    const request = new XMLHttpRequest();
    request.open('POST', 'upload.php');
    request.upload.addEventListener('progress', event => {
      if (event.lengthComputable) bar.value = event.loaded / event.total;
    });
    request.addEventListener('load', () => {
      let answer = {};
      try { answer = JSON.parse(request.responseText); } catch (error) { answer = {}; }
      const saved = (answer.files || [])[0] || {};
      if (request.status === 200 && saved.saved_as) {
        status.textContent = saved.saved_as === file.name ? 'Uploaded' : `Uploaded as ${saved.saved_as}`;
        status.className = 'ok';
      } else {
        status.textContent = saved.error || answer.error || (request.status === 413 ? 'Too large' : `Failed (${request.status})`);
        status.className = 'bad';
      }
      resolve();
    });
    request.addEventListener('error', () => {
      status.textContent = 'Failed (connection)';
      status.className = 'bad';
      resolve();
    });
    request.send(form);
  });
}
