ifeq ($(OS),Windows_NT)
	RM = sh -c 'rm -rf "$$@"' ''
	CP = sh -c 'cp -f "$$@"' ''
else
	RM = rm -fr
	CP = cp -f
endif

ARC_ROOT = ../..
PHUTIL_ROOT = ../../../libphutil

ifdef DEBUG
	CXXFLAGS += -g -DDEBUG
else
	CXXFLAGS += -Os -s -DNDEBUG
endif

CXXFLAGS += -Wall -Wextra -Wconversion -std=c++11
CXXFLAGS += -DWIN32_LEAN_AND_MEAN -DUNICODE -D_UNICODE
CXXFLAGS += -municode -static-libgcc -static-libstdc++
CXXFLAGS += -Wl,--no-insert-timestamp
CXXFLAGS += -I$(PHUTIL_ROOT)/support/win32

.PHONY: all
all: arc.exe

.PHONY: clean
clean:
	$(RM) arc.exe

.PHONY: install
install: arc.exe
	$(CP) arc.exe $(ARC_ROOT)/bin/arc.exe

arc.exe: arc.cpp $(PHUTIL_ROOT)/support/win32/libphutil.a
	$(CXX) $(CXXFLAGS) -o $@ $^

.ALWAYS_REBUILD:

$(PHUTIL_ROOT)/support/win32/libphutil.a: .ALWAYS_REBUILD
	$(MAKE) -C $(PHUTIL_ROOT)/support/win32 libphutil.a
