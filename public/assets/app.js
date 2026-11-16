const statusFilter = document.querySelector('#statusFilter');
if (statusFilter) {
  statusFilter.addEventListener('change', () => {
    document.querySelectorAll('.task-table tbody tr[data-status]').forEach(row => {
      row.hidden = statusFilter.value !== '' && row.dataset.status !== statusFilter.value;
    });
  });
}

const taskProject = document.querySelector('#taskProject');
const taskMember = document.querySelector('#taskMember');
if (taskProject && taskMember) {
  taskProject.addEventListener('change', () => {
    const list = window.projectMembers[taskProject.value] || [];
    taskMember.innerHTML = '<option value="">Selecciona un miembro</option>';
    list.forEach(member => {
      const option = document.createElement('option');
      option.value = member.id;
      option.textContent = member.nombre;
      taskMember.append(option);
    });
  });
}

function prepareTask() {
  document.querySelector('#taskId').value = '';
  document.querySelector('#taskTitle').value = '';
  document.querySelector('#taskDescription').value = '';
  document.querySelector('#taskDate').value = '';
  document.querySelector('#taskProject').value = '';
  document.querySelector('#taskMember').innerHTML = '<option value="">Selecciona el proyecto primero</option>';
  document.querySelector('#taskModal .modal-title').textContent = 'Nueva tarea';
}

function editTask(button) {
  const task = JSON.parse(button.dataset.task);
  prepareTask();
  document.querySelector('#taskId').value = task.id;
  document.querySelector('#taskTitle').value = task.titulo;
  document.querySelector('#taskDescription').value = task.descripcion || '';
  document.querySelector('#taskDate').value = task.fecha_limite || '';
  document.querySelector('#taskProject').value = task.proyecto_id;
  document.querySelector('#taskProject').dispatchEvent(new Event('change'));
  document.querySelector('#taskMember').value = task.usuario_id;
  document.querySelector('#taskModal .modal-title').textContent = 'Editar tarea';
  bootstrap.Modal.getOrCreateInstance(document.querySelector('#taskModal')).show();
}

function openMemberModal(button) {
  document.querySelector('#memberProject').value = button.dataset.project;
  bootstrap.Modal.getOrCreateInstance(document.querySelector('#memberModal')).show();
}
