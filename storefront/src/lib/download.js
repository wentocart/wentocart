export async function downloadCsv(api, path, filename, params = {}) {
    const res = await api.get(path, { params, responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}
