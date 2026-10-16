(() => {
  const form = document.querySelector("#student-form");
  if (!form) return;
  const fields = {
    name: document.querySelector("#student-name"),
    student_id: document.querySelector("#student-number"),
    school: document.querySelector("#school-name"),
    department: document.querySelector("#department"),
    valid_until: document.querySelector("#valid-until")
  };
  const preview = {
    name: document.querySelector("#card-name"),
    student_id: document.querySelector("#card-student-id"),
    school: document.querySelector("#card-school"),
    department: document.querySelector("#card-department"),
    valid_until: document.querySelector("#card-valid-until"),
    photo: document.querySelector("#card-photo")
  };
  const initialValues = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field.value]));
  const defaultPhoto = "assets/images/default-avatar.svg";
  function formatDate(value) {
    if (!value) return "NOT SET";
    const date = new Date(value + "T00:00:00");
    if (Number.isNaN(date.getTime())) return "NOT SET";
    return date.toLocaleDateString("en-US", {month:"short",day:"2-digit",year:"numeric"}).toUpperCase();
  }
  function updatePreview() {
    preview.name.textContent = fields.name.value.trim() || "Student Name";
    preview.student_id.textContent = fields.student_id.value.trim() || "STUDENT ID";
    preview.school.textContent = fields.school.value.trim() || "School or University";
    preview.department.textContent = fields.department.value.trim() || "Department not set";
    preview.valid_until.textContent = formatDate(fields.valid_until.value);
  }
  Object.values(fields).forEach(field => field.addEventListener("input", updatePreview));
  document.querySelector("#photo").addEventListener("change", event => {
    const file = event.target.files && event.target.files[0];
    if (!file) return;
    if (!["image/jpeg","image/png","image/webp"].includes(file.type)) {
      window.alert("Choose a JPG, PNG, or WebP image.");
      event.target.value = "";
      return;
    }
    const reader = new FileReader();
    reader.addEventListener("load", () => { preview.photo.src = reader.result; });
    reader.readAsDataURL(file);
  });
  document.querySelector("#reset-button").addEventListener("click", () => {
    Object.entries(initialValues).forEach(([key,value]) => { fields[key].value = value; });
    document.querySelector("#photo").value = "";
    preview.photo.src = defaultPhoto;
    updatePreview();
  });
  document.querySelector("#print-button").addEventListener("click", () => window.print());
  updatePreview();
})();
