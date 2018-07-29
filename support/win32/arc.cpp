#include <Windows.h>

#include <string>
#include <vector>

#include "Phutil.h"

namespace
{
bool IsRegularFile(const std::wstring& path) {
  DWORD attrs = GetFileAttributes(path.c_str());
  return attrs != INVALID_FILE_ATTRIBUTES &&
         !(attrs & FILE_ATTRIBUTE_DIRECTORY);
}

std::wstring Dirname(const std::wstring& path)
{
    return Phutil::SplitPath(path).first;
}
}  // namespace

int wmain(int argc, const wchar_t* argv[]) {
  const std::wstring arcExe = Phutil::GetExecutablePath();
  const std::wstring arcRoot = Dirname(Dirname(arcExe));
  const std::wstring arcanistScript = arcRoot + L"\\scripts\\arcanist.php";

  const std::wstring searchRoot = Dirname(arcRoot);
  const std::wstring candidates[] = {
    searchRoot + L"\\php-7\\php.exe",
    searchRoot + L"\\php7\\php.exe",
    searchRoot + L"\\php-5\\php.exe",
    searchRoot + L"\\php5\\php.exe",
    searchRoot + L"\\php\\php.exe",
  };

  std::wstring php = L"php.exe";
  for (const auto& candidate : candidates) {
    if (IsRegularFile(candidate)) {
      php = candidate;

      // Also add this PHP to PATH.
      std::wstring path = Phutil::GetEnv(L"PATH");
      path.append(L";").append(Dirname(candidate));
      Phutil::SetEnv(L"PATH", path);

      break;
    }
  }

  std::vector<const wchar_t*> newArgv = {
      php.c_str(),
      L"-f",
      arcanistScript.c_str(),
      L"--",
  };

  for (int i = 1; i < argc; ++i) {
    newArgv.push_back(argv[i]);
  }

  return Phutil::CallProcess(static_cast<int>(newArgv.size()), newArgv.data());
}
